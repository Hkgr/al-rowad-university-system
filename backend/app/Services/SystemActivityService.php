<?php

namespace App\Services;

use App\Models\User;
use App\Support\AccountAdministration;
use App\Support\SystemActivityCatalog;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

/**
 * Read-only, filtered and sanitized activity feed for the Technical Office.
 * Sources: user_activity_logs (service audit events) and login_audit_logs
 * (sign-in attempts). Rows are never modified or deleted here.
 *
 * Scope: technical_team sees account/security, identity, administrative-VP
 * account and generic record-change events (SystemActivityCatalog::TECHNICAL_MODULES);
 * super_admin sees every module. Details are reduced to an allowlist of safe
 * keys; anything that looks like a secret is dropped for every viewer.
 */
class SystemActivityService
{
    public function __construct(private readonly AccountAdministrationService $accounts) {}

    /** @return list<string>|null null = every module */
    public function visibleModules(User $viewer): ?array
    {
        return $this->accounts->isSuperAdmin($viewer) ? null : SystemActivityCatalog::TECHNICAL_MODULES;
    }

    /** @return array{data: list<array<string, mixed>>, meta: array<string, int>} */
    public function list(User $viewer, array $filters): array
    {
        $modules = $this->visibleModules($viewer);
        $from = isset($filters['from']) ? Carbon::parse($filters['from'])->startOfDay() : null;
        $to = isset($filters['to']) ? Carbon::parse($filters['to'])->endOfDay() : null;
        if ($from && $to && $from->greaterThan($to)) {
            throw ValidationException::withMessages(['from' => ['بداية الفترة بعد نهايتها.']]);
        }
        $actorIds = $this->actorIds($filters['actor'] ?? null);
        $module = $filters['module'] ?? null;
        $action = $filters['action'] ?? null;
        $source = $filters['source'] ?? null;
        $search = trim((string) ($filters['search'] ?? ''));
        $targetUserId = isset($filters['target_user_id']) ? (int) $filters['target_user_id'] : null;

        $parts = [];
        $wantsLogin = $source !== 'activity' && ($module === null || $module === SystemActivityCatalog::LOGIN_MODULE)
            && ($action === null || str_starts_with($action, 'login.'))
            && ($modules === null || in_array(SystemActivityCatalog::LOGIN_MODULE, $modules, true))
            && Schema::hasTable('login_audit_logs');
        $wantsActivity = $source !== 'login' && $module !== SystemActivityCatalog::LOGIN_MODULE
            && ($action === null || ! str_starts_with($action, 'login.'))
            && Schema::hasTable('user_activity_logs');

        if ($wantsActivity) {
            $q = DB::table('user_activity_logs as l')->select([
                DB::raw("'activity' as source"), 'l.activity_log_id as id', 'l.created_at as occurred_at', 'l.user_id as actor_user_id',
                'l.module_code as module', 'l.action_code as action', 'l.description as details', 'l.ip_address', DB::raw('NULL as user_agent'),
            ]);
            if ($modules !== null) {
                $q->whereIn('l.module_code', $modules);
            }
            $this->filterCommon($q, 'l.created_at', 'l.user_id', $from, $to, $actorIds);
            if ($module !== null) {
                $q->where('l.module_code', $module);
            }
            if ($action !== null) {
                $q->where('l.action_code', $action);
            }
            if ($targetUserId !== null) {
                $q->where(fn (Builder $w) => $this->whereTargetsAny($w, [$targetUserId]));
            }
            if ($search !== '') {
                // Safe fields only; the raw description is never matched (see searchTerms()).
                $terms = $this->searchTerms($search, $modules);
                $q->where(fn (Builder $w) => $w->whereIn('l.action_code', $terms['actions'] ?: [''])
                    ->orWhere('l.ip_address', 'like', $terms['like'])
                    ->orWhereIn('l.user_id', $terms['user_ids'])
                    ->orWhere(fn (Builder $t) => $this->whereTargetsAny($t, $terms['user_ids'])));
            }
            $parts[] = $q;
        }
        if ($wantsLogin) {
            $q = DB::table('login_audit_logs as g')->select([
                DB::raw("'login' as source"), 'g.login_audit_id as id', 'g.attempted_at as occurred_at', 'g.user_id as actor_user_id',
                DB::raw("'".SystemActivityCatalog::LOGIN_MODULE."' as module"), 'g.login_status as action', 'g.username_attempted as details', 'g.ip_address', 'g.user_agent',
            ]);
            $this->filterCommon($q, 'g.attempted_at', 'g.user_id', $from, $to, $actorIds);
            if ($action !== null) {
                $q->where('g.login_status', substr($action, strlen('login.')));
            }
            if ($targetUserId !== null) {
                $q->where('g.user_id', $targetUserId);
            }
            if ($search !== '') {
                // The attempted identifier is never matched: it is shown masked, and a
                // full-address (or mistyped password) search must not confirm its value.
                $terms = $this->searchTerms($search, $modules);
                $statuses = array_map(fn ($code) => substr($code, strlen('login.')), array_filter($terms['actions'], fn ($code) => str_starts_with($code, 'login.')));
                $q->where(fn (Builder $w) => $w->whereIn('g.login_status', $statuses ?: [''])
                    ->orWhere('g.ip_address', 'like', $terms['like'])
                    ->orWhereIn('g.user_id', $terms['user_ids']));
            }
            $parts[] = $q;
        }

        $perPage = max(1, min(100, (int) ($filters['per_page'] ?? 25)));
        $page = max(1, (int) ($filters['page'] ?? 1));
        if ($parts === []) {
            return ['data' => [], 'meta' => ['current_page' => 1, 'last_page' => 1, 'per_page' => $perPage, 'total' => 0]];
        }
        $union = array_shift($parts);
        foreach ($parts as $part) {
            $union->unionAll($part);
        }
        $feed = DB::query()->fromSub($union, 'feed');
        $total = (clone $feed)->count();
        $rows = $feed->orderByDesc('occurred_at')->orderBy('source')->orderByDesc('id')
            ->forPage($page, $perPage)->get();

        return [
            'data' => $this->present($rows, $viewer, false),
            'meta' => ['current_page' => $page, 'last_page' => max(1, (int) ceil($total / $perPage)), 'per_page' => $perPage, 'total' => $total],
        ];
    }

    public function show(User $viewer, string $source, int $id): ?array
    {
        $modules = $this->visibleModules($viewer);
        if ($source === 'login') {
            if ($modules !== null && ! in_array(SystemActivityCatalog::LOGIN_MODULE, $modules, true)) {
                return null;
            }
            $row = DB::table('login_audit_logs')->where('login_audit_id', $id)->first();
            $row = $row === null ? null : (object) ['source' => 'login', 'id' => $row->login_audit_id, 'occurred_at' => $row->attempted_at, 'actor_user_id' => $row->user_id,
                'module' => SystemActivityCatalog::LOGIN_MODULE, 'action' => $row->login_status, 'details' => $row->username_attempted, 'ip_address' => $row->ip_address, 'user_agent' => $row->user_agent];
        } else {
            $row = DB::table('user_activity_logs')->where('activity_log_id', $id)->first();
            if ($row !== null && $modules !== null && ! in_array($row->module_code, $modules, true)) {
                return null;
            }
            $row = $row === null ? null : (object) ['source' => 'activity', 'id' => $row->activity_log_id, 'occurred_at' => $row->created_at, 'actor_user_id' => $row->user_id,
                'module' => $row->module_code, 'action' => $row->action_code, 'details' => $row->description, 'ip_address' => $row->ip_address, 'user_agent' => null];
        }

        return $row === null ? null : $this->present(collect([$row]), $viewer, true)[0];
    }

    /** @return array<string, mixed> */
    public function options(User $viewer): array
    {
        $modules = $this->visibleModules($viewer);
        $moduleCodes = $modules ?? array_keys(SystemActivityCatalog::MODULES);

        return [
            'modules' => array_map(fn ($code) => ['code' => $code, 'label' => SystemActivityCatalog::moduleLabel($code)], $moduleCodes),
            'actions' => collect(SystemActivityCatalog::ACTIONS)
                ->filter(fn ($meta) => $modules === null || in_array($meta['module'], $modules, true))
                ->map(fn ($meta, $code) => ['code' => $code, 'label' => $meta['label'], 'module' => $meta['module']])
                ->values()->all(),
            'scope' => $modules === null ? 'all_modules' : 'technical_modules',
            'can_open_accounts' => $viewer->hasPermission(AccountAdministration::VIEW),
        ];
    }

    // ── presentation ─────────────────────────────────────────────────────────

    /** @return list<array<string, mixed>> */
    private function present($rows, User $viewer, bool $full): array
    {
        $userIds = $rows->pluck('actor_user_id')->filter()->all();
        $details = $rows->mapWithKeys(fn ($row) => [$row->source.':'.$row->id => $this->details($row)]);
        foreach ($details as $detail) {
            foreach (['target_user_id', 'user_id'] as $key) {
                if (isset($detail['fields'][$key]) && is_int($detail['fields'][$key])) {
                    $userIds[] = $detail['fields'][$key];
                }
            }
        }
        $usernames = $userIds === [] ? collect() : DB::table('users')->whereIn('user_id', array_unique($userIds))->pluck('username', 'user_id');
        $canOpen = $viewer->hasPermission(AccountAdministration::VIEW);

        return $rows->map(function ($row) use ($details, $usernames, $canOpen, $full): array {
            $detail = $details[$row->source.':'.$row->id];
            $action = $row->source === 'login' ? 'login.'.$row->action : (string) $row->action;
            $targetId = $row->source === 'login' ? $row->actor_user_id : ($detail['fields']['target_user_id'] ?? null);
            $item = [
                'key' => $row->source.':'.$row->id,
                'source' => $row->source,
                'id' => (int) $row->id,
                'occurred_at' => Carbon::parse($row->occurred_at)->toIso8601String(),
                'actor' => $row->actor_user_id === null ? null : ['user_id' => (int) $row->actor_user_id, 'username' => $usernames[$row->actor_user_id] ?? null],
                'module' => ['code' => $row->module, 'label' => SystemActivityCatalog::moduleLabel($row->module)],
                'action' => ['code' => $action, 'label' => SystemActivityCatalog::actionLabel($action)],
                'outcome' => $detail['outcome'],
                'target' => $targetId === null ? null : [
                    'user_id' => (int) $targetId,
                    'username' => $usernames[$targetId] ?? null,
                    'link' => $canOpen ? '/technical/accounts?user='.(int) $targetId : null,
                ],
                'summary' => SystemActivityCatalog::summary($action, $detail['fields'], $detail['outcome']),
                'changes' => $detail['changes'],
                'details_available' => ! $detail['legacy'],
            ];
            if ($full) {
                $item['fields'] = $detail['fields'];
                $item['ip_address'] = $row->ip_address;
                $item['user_agent'] = $row->user_agent === null ? null : SystemActivityCatalog::scrubText(mb_substr((string) $row->user_agent, 0, 120));
                $item['notes'] = $detail['notes'];
            }

            return $item;
        })->values()->all();
    }

    /** @return array{fields: array<string, mixed>, changes: list<array<string, mixed>>, outcome: string, notes: list<string>} */
    private function details(object $row): array
    {
        if ($row->source === 'login') {
            $status = (string) $row->action;

            return [
                'fields' => ['identifier' => SystemActivityCatalog::maskIdentifier($row->details)],
                'changes' => [],
                'outcome' => in_array($status, ['success', 'logout'], true) ? 'success' : 'failed',
                'notes' => [],
                'legacy' => false,
            ];
        }
        $decoded = is_string($row->details) ? json_decode($row->details, true) : null;
        if (! is_array($decoded)) {
            // Legacy free text is never echoed (it may hold an address, a password or a token).
            return ['fields' => [], 'changes' => [], 'outcome' => 'success', 'notes' => [SystemActivityCatalog::LEGACY_NOTE], 'legacy' => true];
        }

        return SystemActivityCatalog::sanitize((string) $row->action, $decoded) + ['legacy' => false];
    }

    // ── helpers ──────────────────────────────────────────────────────────────

    private function filterCommon(Builder $q, string $timeColumn, string $actorColumn, ?Carbon $from, ?Carbon $to, ?array $actorIds): void
    {
        if ($from) {
            $q->where($timeColumn, '>=', $from->toDateTimeString());
        }
        if ($to) {
            $q->where($timeColumn, '<=', $to->toDateTimeString());
        }
        if ($actorIds !== null) {
            $q->whereIn($actorColumn, $actorIds === [] ? [0] : $actorIds);
        }
    }

    /** @return list<int>|null */
    private function actorIds(?string $actor): ?array
    {
        $actor = trim((string) $actor);
        if ($actor === '') {
            return null;
        }
        if (ctype_digit($actor)) {
            return [(int) $actor];
        }

        return DB::table('users')->whereRaw('LOWER(username) = ?', [mb_strtolower($actor)])->pluck('user_id')->map(fn ($id) => (int) $id)->all();
    }

    /**
     * What the free-text search may match — and nothing else:
     *  - an account username (as the actor or as the affected account),
     *  - an action code or its Arabic label (within the viewer's modules),
     *  - an IP address.
     * Descriptions, e-mail addresses and attempted login identifiers are never
     * searched, so a hit can never confirm a value that the feed masks.
     *
     * @param  list<string>|null  $modules
     * @return array{like: string, user_ids: list<int>, actions: list<string>}
     */
    private function searchTerms(string $search, ?array $modules): array
    {
        $like = $this->like($search);
        $needle = mb_strtolower($search);
        $actions = collect(SystemActivityCatalog::ACTIONS)
            ->filter(fn ($meta, $code) => ($modules === null || in_array($meta['module'], $modules, true))
                && (str_contains(mb_strtolower($code), $needle) || str_contains($meta['label'], $search)))
            ->keys()->all();
        $userIds = DB::table('users')->where('username', 'like', $like)->limit(50)->pluck('user_id')->map(fn ($id) => (int) $id)->all();

        return ['like' => $like, 'user_ids' => $userIds ?: [0], 'actions' => $actions];
    }

    /**
     * Structured events whose target is one of the given accounts. Only the
     * JSON key/number pattern is matched (never user-supplied text), and only
     * on structured (JSON) descriptions.
     *
     * @param  list<int>  $userIds
     */
    private function whereTargetsAny(Builder $w, array $userIds): void
    {
        $w->where('l.description', 'like', '{%')->where(function (Builder $any) use ($userIds): void {
            foreach ($userIds as $id) {
                foreach (['target_user_id', 'user_id'] as $key) {
                    $any->orWhere('l.description', 'like', '%"'.$key.'":'.(int) $id.',%')
                        ->orWhere('l.description', 'like', '%"'.$key.'":'.(int) $id.'}%');
                }
            }
        });
    }

    private function like(string $value): string
    {
        return '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $value).'%';
    }
}
