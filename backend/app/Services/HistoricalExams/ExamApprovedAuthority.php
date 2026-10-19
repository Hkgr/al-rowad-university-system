<?php

namespace App\Services\HistoricalExams;

use App\Services\StudentCompletion\CompletionData;

/** Approved inputs remain private. The inventory is evidence, never an executable mapping. */
final class ExamApprovedAuthority
{
    public const REVISION = 'historical-exams-approved-reconciliation.2026-10-18.v1';
    public const PINS = [
        'PROMPT.md' => '0e78cd5a1a7724d9fb4890ab3e3b434cca76eced8a2481551aa3d80de7273276',
        'README_AR.md' => 'f18c32d94595ccb92a97d2166005d1c5f2c34f1ad02d7f49184f31cfde52baa2',
        'approved-decisions.json' => '28fa1d74c20201e9ca2ed55778144baeccca0bde37519a950479426f67587bfe',
        'course-identity-review.json' => '11e4b7fe0ef066e8b0fa1290fad3bc56fd4f99df672ec22433b037ce533abec7',
        'verified-source-findings.json' => 'f3b6dd3ead0b2d5fd12aef73ad0672eb3eb17bcd9539ea89ed5988c03f303fbf',
        'identity-and-period-controls.json' => '10e68822489c7b8720131e80887776ab5d9a0da819daf3bbb274129b3ab5354e',
        'source-manifest.json' => '3e3555a27c1179ae1ec4093bc9e3781eb4de3ef58690b95517b37fd8f8ff6ac4',
    ];

    // Constructor substitution is for synthetic fixtures, not a command option or user-supplied policy.
    public function __construct(private readonly array $pins = self::PINS) {}

    public function read(string $directory, array $sources): array
    {
        $documents = [];
        foreach ($this->pins as $name => $hash) {
            $path = $directory.DIRECTORY_SEPARATOR.$name;
            if (! is_file($path) || ! hash_equals($hash, hash_file('sha256', $path))) {
                throw new \InvalidArgumentException('Approved handoff missing or changed: '.$name);
            }
            if (str_ends_with($name, '.json')) {
                $documents[$name] = json_decode(file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
            }
        }
        $manifest = $documents['source-manifest.json'];
        foreach (['exam1', 'exam2'] as $key) {
            $pin = collect($manifest['sources'])->firstWhere('key', $key);
            if (! $pin || ! hash_equals($pin['sha256'], $sources[$key]['sha256'])) {
                throw new \InvalidArgumentException('Approved examination bytes differ: '.$key);
            }
        }
        if (($documents['approved-decisions.json']['schema'] ?? null) !== 'alrowad-exam-approved-decisions-handoff.v1') {
            throw new \InvalidArgumentException('Unsupported approved decision schema.');
        }

        return ['revision' => self::REVISION, 'document_hashes' => $this->pins, 'documents' => $documents,
            'snapshot_policy' => 'Analysis IDs are assertions to revalidate against the actual snapshot and live identities, not selectors.'];
    }

    /** Exact source references first; original source facts are never edited. */
    public function index(array $authority): array
    {
        if (($authority['revision'] ?? null) !== self::REVISION) {
            throw new \InvalidArgumentException('Unsupported approved reconciliation revision.');
        }
        $documents = $authority['documents'];
        $decisions = $documents['approved-decisions.json']['decisions'];
        $index = ['authority' => $authority, 'inventory' => [], 'deferred' => [], 'absent_final' => [],
            'sitting_year' => [], 'selected' => [], 'superseded' => [], 'owners' => []];
        foreach ($documents['course-identity-review.json']['groups'] as $group) {
            foreach ($group['references'] as $reference) {
                if (isset($index['inventory'][$reference])) {
                    throw new \InvalidArgumentException('Duplicate course evidence reference: '.$reference);
                }
                $index['inventory'][$reference] = array_diff_key($group, ['references' => true]);
            }
        }
        foreach ($decisions as $number => $decision) {
            if (in_array($number, [8, 9, 10, 14], true)) {
                foreach ($decision['source_references'] as $reference) $index['deferred'][$reference][] = 'approved_deferral:'.($number + 1);
            }
            if ($number === 11) $index['absent_final'] = array_fill_keys($decision['source_references'], true);
            if ($number === 16) foreach ($decision['source_references'] as $reference) $index['sitting_year'][$reference] = $decision['approved_sitting_academic_year'];
            if (in_array($number, [4, 6, 7], true) && isset($decision['selected_source_reference'])) {
                $index['selected'][$decision['selected_source_reference']] = ['decision' => $number + 1, 'expected' => $decision['approved_values']];
                foreach (['superseded_source_reference', 'other_source_reference'] as $field) {
                    if (isset($decision[$field])) $index['superseded'][$decision[$field]] = $decision['selected_source_reference'];
                }
            }
            if ($number === 5) foreach ($decision['selected_records']??[] as $record) {
                $index['selected'][$record['selected_source_reference']] = ['decision' => 6, 'expected' => $record['approved_values'] ?? $record];
                if (isset($record['superseded_source_reference'])) $index['superseded'][$record['superseded_source_reference']] = $record['selected_source_reference'];
            }
            if (isset($decision['student_number']) && isset($decision['source_name_aliases'])) {
                $index['owners'][$decision['student_number']] = $decision;
            }
        }

        return $index;
    }

    public static function label(mixed $value): string
    {
        // Delegated typography only. No edit distance, token reordering or number stripping.
        $value = strtr(CompletionData::text($value), ['أ' => 'ا', 'إ' => 'ا', 'آ' => 'ا', 'ى' => 'ي', 'ی' => 'ي', 'ک' => 'ك', 'ة' => 'ه', 'ـ' => '']);
        return preg_replace('/[\s()（）_–—\-،,\.]+/u', '', $value);
    }
}
