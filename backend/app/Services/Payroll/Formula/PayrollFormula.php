<?php

namespace App\Services\Payroll\Formula;

use App\Services\Payroll\PayrollCalculator;
use Brick\Math\BigDecimal;

/**
 * Restricted formula language of the payroll sheet. No eval, no variables, no macros, no cross-row references.
 *
 *   formula    := ['='] comparison
 *   comparison := additive [ ('=' | '<>' | '<' | '<=' | '>' | '>=') additive ]
 *   additive   := term (('+' | '-') term)*
 *   term       := unary (('*' | '/') unary)*
 *   unary      := ('-' | '+') unary | primary ['%']
 *   primary    := NUMBER ['%'] | "text" | '[' column or setting name ']' | '{' stable key '}' | FUNC '(' args ')' | '(' comparison ')'
 *   FUNC       := SUM | IF | MAX | MIN | ROUND
 *
 * Types: number, text, and bool (only a comparison, only as the first argument of IF). A formula column yields a number.
 * AST nodes: num{v}, str{v}, ref{k,src}, neg{a}, pct{a}, bin{op,l,r}, cmp{op,l,r}, call{f,args}.
 */
final class PayrollFormula
{
    public const MAX_LENGTH = 600;

    public const MAX_NODES = 120;

    public const MAX_DEPTH = 24;

    public const FUNCTIONS = ['SUM', 'IF', 'MAX', 'MIN', 'ROUND'];

    private array $tokens = [];

    private int $index = 0;

    private int $nodes = 0;

    private function __construct(private readonly string $source, private readonly FormulaScope $scope) {}

    /** Parse a formula typed with [display names] and/or stored with {stable keys}. Returns the AST of a numeric formula. */
    public static function parse(string $source, FormulaScope $scope): array
    {
        $parser = new self(self::normalize($source), $scope);
        if (mb_strlen($parser->source) > self::MAX_LENGTH) {
            throw new FormulaException('المعادلة أطول من الحد المسموح ('.self::MAX_LENGTH.' حرفًا).', 'complexity');
        }
        $parser->tokens = $parser->tokenize();
        if ($parser->tokens === []) {
            throw new FormulaException('المعادلة فارغة.', 'syntax');
        }
        $ast = $parser->comparison(1);
        if ($parser->index < count($parser->tokens)) {
            throw new FormulaException('رمز غير متوقع في المعادلة: «'.$parser->tokens[$parser->index]['text'].'».', 'syntax', $parser->tokens[$parser->index]['pos']);
        }
        $type = self::typeOf($ast, $scope);
        if ($type !== 'number') {
            throw new FormulaException($type === 'bool' ? 'نتيجة المعادلة يجب أن تكون رقمًا؛ المقارنة تُستعمل داخل IF فقط.' : 'نتيجة المعادلة يجب أن تكون رقمًا لا نصًا.', 'type');
        }

        return $ast;
    }

    /** Arabic digits/separators and a leading "=" are accepted on input. */
    public static function normalize(string $source): string
    {
        $source = strtr($source, [
            '٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4', '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9',
            '۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4', '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9',
            '٫' => '.', '٪' => '%', '،' => ',', '؛' => ',', ';' => ',', "\u{00A0}" => ' ', '×' => '*', '÷' => '/', '−' => '-',
        ]);
        $source = trim($source);

        return str_starts_with($source, '=') && ! str_starts_with($source, '==') ? ltrim(substr($source, 1)) : $source;
    }

    // ── tokenizer ─────────────────────────────────────────────────────────

    private function tokenize(): array
    {
        $tokens = [];
        $s = $this->source;
        $length = mb_strlen($s);
        for ($i = 0; $i < $length;) {
            $c = mb_substr($s, $i, 1);
            if (preg_match('/\s/u', $c)) {
                $i++;

                continue;
            }
            if ($c === '[' || $c === '{') {
                $close = $c === '[' ? ']' : '}';
                $end = mb_strpos($s, $close, $i + 1);
                if ($end === false) {
                    throw new FormulaException('مرجع غير مغلق: أضف '.$close.'.', 'syntax', $i);
                }
                $name = trim(mb_substr($s, $i + 1, $end - $i - 1));
                if ($name === '') {
                    throw new FormulaException('مرجع فارغ.', 'syntax', $i);
                }
                $tokens[] = ['t' => $c === '[' ? 'label' : 'key', 'v' => $name, 'text' => mb_substr($s, $i, $end - $i + 1), 'pos' => $i];
                $i = $end + 1;

                continue;
            }
            if ($c === '"') {
                $end = mb_strpos($s, '"', $i + 1);
                if ($end === false) {
                    throw new FormulaException('نص غير مغلق: أضف ".', 'syntax', $i);
                }
                $tokens[] = ['t' => 'str', 'v' => mb_substr($s, $i + 1, $end - $i - 1), 'text' => mb_substr($s, $i, $end - $i + 1), 'pos' => $i];
                $i = $end + 1;

                continue;
            }
            if (preg_match('/^(\d+(?:\.\d+)?|\.\d+)/u', mb_substr($s, $i, 40), $m)) {
                $number = $m[1][0] === '.' ? '0'.$m[1] : $m[1];
                [$int, $dec] = array_pad(explode('.', $number, 2), 2, '');
                if (strlen(ltrim($int, '0')) > 12 || strlen($dec) > 10) {
                    throw new FormulaException('رقم كبير أو دقيق أكثر من اللازم: '.$m[1], 'complexity', $i);
                }
                $tokens[] = ['t' => 'num', 'v' => $number, 'text' => $m[1], 'pos' => $i];
                $i += mb_strlen($m[1]);

                continue;
            }
            if (preg_match('/^[A-Za-z]+/', mb_substr($s, $i, 20), $m)) {
                $tokens[] = ['t' => 'func', 'v' => strtoupper($m[0]), 'text' => $m[0], 'pos' => $i];
                $i += strlen($m[0]);

                continue;
            }
            $two = mb_substr($s, $i, 2);
            if (in_array($two, ['<>', '<=', '>='], true)) {
                $tokens[] = ['t' => 'op', 'v' => $two, 'text' => $two, 'pos' => $i];
                $i += 2;

                continue;
            }
            if (str_contains('+-*/(),%=<>', $c)) {
                $tokens[] = ['t' => 'op', 'v' => $c, 'text' => $c, 'pos' => $i];
                $i++;

                continue;
            }
            throw new FormulaException('رمز غير مسموح في المعادلة: «'.$c.'».', 'syntax', $i);
        }

        return $tokens;
    }

    // ── parser ────────────────────────────────────────────────────────────

    private function peek(): ?array
    {
        return $this->tokens[$this->index] ?? null;
    }

    private function isOp(string ...$ops): bool
    {
        $t = $this->peek();

        return $t !== null && $t['t'] === 'op' && in_array($t['v'], $ops, true);
    }

    private function expectOp(string $op): void
    {
        if (! $this->isOp($op)) {
            $t = $this->peek();
            throw new FormulaException('متوقع «'.$op.'»'.($t ? ' قبل «'.$t['text'].'»' : ' في نهاية المعادلة').'.', 'syntax', $t['pos'] ?? mb_strlen($this->source));
        }
        $this->index++;
    }

    private function node(array $node, int $depth): array
    {
        if (++$this->nodes > self::MAX_NODES) {
            throw new FormulaException('المعادلة معقدة أكثر من اللازم (الحد '.self::MAX_NODES.' عنصرًا).', 'complexity');
        }
        if ($depth > self::MAX_DEPTH) {
            throw new FormulaException('تداخل الأقواس أو الدوال أعمق من اللازم.', 'complexity');
        }

        return $node;
    }

    private function comparison(int $depth): array
    {
        $left = $this->additive($depth);
        if ($this->isOp('=', '<>', '<', '<=', '>', '>=')) {
            $op = $this->peek()['v'];
            $this->index++;
            $right = $this->additive($depth);
            if ($this->isOp('=', '<>', '<', '<=', '>', '>=')) {
                throw new FormulaException('لا يمكن تسلسل مقارنتين؛ استعمل IF متداخلة.', 'syntax', $this->peek()['pos']);
            }

            return $this->node(['t' => 'cmp', 'op' => $op, 'l' => $left, 'r' => $right], $depth);
        }

        return $left;
    }

    private function additive(int $depth): array
    {
        $left = $this->term($depth);
        while ($this->isOp('+', '-')) {
            $op = $this->peek()['v'];
            $this->index++;
            $left = $this->node(['t' => 'bin', 'op' => $op, 'l' => $left, 'r' => $this->term($depth + 1)], $depth);
        }

        return $left;
    }

    private function term(int $depth): array
    {
        $left = $this->unary($depth);
        while ($this->isOp('*', '/')) {
            $op = $this->peek()['v'];
            $this->index++;
            $left = $this->node(['t' => 'bin', 'op' => $op, 'l' => $left, 'r' => $this->unary($depth + 1)], $depth);
        }

        return $left;
    }

    private function unary(int $depth): array
    {
        if ($this->isOp('-')) {
            $this->index++;

            return $this->node(['t' => 'neg', 'a' => $this->unary($depth + 1)], $depth);
        }
        if ($this->isOp('+')) {
            $this->index++;

            return $this->unary($depth + 1);
        }
        $node = $this->primary($depth);
        while ($this->isOp('%')) {
            $this->index++;
            $node = $this->node(['t' => 'pct', 'a' => $node], $depth);
        }

        return $node;
    }

    private function primary(int $depth): array
    {
        $t = $this->peek();
        if ($t === null) {
            throw new FormulaException('المعادلة غير مكتملة.', 'syntax', mb_strlen($this->source));
        }
        $this->index++;
        switch ($t['t']) {
            case 'num':
                return $this->node(['t' => 'num', 'v' => PayrollCalculator::plain(BigDecimal::of($t['v']))], $depth);
            case 'str':
                return $this->node(['t' => 'str', 'v' => $t['v']], $depth);
            case 'label':
            case 'key':
                $entry = $t['t'] === 'label' ? $this->scope->byLabel($t['v']) : $this->scope->byKey($t['v']);
                if ($entry === null) {
                    throw new FormulaException('مرجع غير معروف: «'.$t['v'].'». اختر اسم عمود أو إعداد موجود.', 'unknown_reference', $t['pos']);
                }

                return $this->node(['t' => 'ref', 'k' => $entry['key'], 'src' => $entry['source']], $depth);
            case 'func':
                if (! in_array($t['v'], self::FUNCTIONS, true)) {
                    throw new FormulaException('دالة غير مدعومة: '.$t['text'].'. المدعوم: '.implode('، ', self::FUNCTIONS).'.', 'unsupported_function', $t['pos']);
                }
                $this->expectOp('(');
                $args = [];
                if (! $this->isOp(')')) {
                    do {
                        $args[] = $this->comparison($depth + 1);
                        $more = $this->isOp(',');
                        if ($more) {
                            $this->index++;
                        }
                    } while ($more);
                }
                $this->expectOp(')');

                return $this->node(['t' => 'call', 'f' => $t['v'], 'args' => $args], $depth);
            case 'op':
                if ($t['v'] === '(') {
                    $inner = $this->comparison($depth + 1);
                    $this->expectOp(')');

                    return $inner;
                }
        }
        throw new FormulaException('رمز غير متوقع: «'.$t['text'].'».', 'syntax', $t['pos']);
    }

    // ── static analysis ───────────────────────────────────────────────────

    /** @return 'number'|'text'|'bool' */
    public static function typeOf(array $n, FormulaScope $scope): string
    {
        switch ($n['t']) {
            case 'num':
                return 'number';
            case 'str':
                return 'text';
            case 'ref':
                return $scope->byKey($n['k'])['type'] ?? throw new FormulaException('مرجع غير معروف: '.$n['k'], 'unknown_reference');
            case 'neg':
            case 'pct':
                self::need(self::typeOf($n['a'], $scope), 'number', $n['t'] === 'neg' ? 'الإشارة السالبة' : 'علامة %');

                return 'number';
            case 'bin':
                self::need(self::typeOf($n['l'], $scope), 'number', 'العملية '.$n['op']);
                self::need(self::typeOf($n['r'], $scope), 'number', 'العملية '.$n['op']);

                return 'number';
            case 'cmp':
                $l = self::typeOf($n['l'], $scope);
                $r = self::typeOf($n['r'], $scope);
                if ($l === 'bool' || $r === 'bool' || $l !== $r) {
                    throw new FormulaException('لا يمكن مقارنة قيمتين من نوعين مختلفين (رقم مع نص).', 'type');
                }
                if ($l === 'text' && ! in_array($n['op'], ['=', '<>'], true)) {
                    throw new FormulaException('النصوص تُقارَن بـ = أو <> فقط.', 'type');
                }

                return 'bool';
            case 'call':
                return self::typeOfCall($n, $scope);
        }
        throw new FormulaException('عنصر غير معروف في المعادلة.', 'syntax');
    }

    private static function need(string $actual, string $expected, string $where): void
    {
        if ($actual !== $expected) {
            throw new FormulaException("نوع غير متوافق في {$where}: المطلوب ".($expected === 'number' ? 'رقم' : 'نص').' وليس '.($actual === 'text' ? 'نصًا' : ($actual === 'bool' ? 'مقارنة' : 'رقمًا')).'.', 'type');
        }
    }

    private static function typeOfCall(array $n, FormulaScope $scope): string
    {
        $f = $n['f'];
        $args = $n['args'];
        $count = count($args);
        switch ($f) {
            case 'SUM':
            case 'MAX':
            case 'MIN':
                if ($count < 1) {
                    throw new FormulaException("الدالة {$f} تحتاج وسيطًا واحدًا على الأقل.", 'syntax');
                }
                foreach ($args as $a) {
                    self::need(self::typeOf($a, $scope), 'number', "الدالة {$f}");
                }

                return 'number';
            case 'ROUND':
                if ($count !== 2) {
                    throw new FormulaException('الدالة ROUND تأخذ وسيطين: ROUND(قيمة; عدد المنازل).', 'syntax');
                }
                self::need(self::typeOf($args[0], $scope), 'number', 'الدالة ROUND');
                if ($args[1]['t'] !== 'num' || ! preg_match('/^[0-6]$/', $args[1]['v'])) {
                    throw new FormulaException('عدد المنازل في ROUND يجب أن يكون رقمًا ثابتًا صحيحًا من 0 إلى 6.', 'type');
                }

                return 'number';
            case 'IF':
                if ($count !== 3) {
                    throw new FormulaException('الدالة IF تأخذ ثلاثة وسائط: IF(شرط, إذا صح, إذا لم يصح).', 'syntax');
                }
                if (self::typeOf($args[0], $scope) !== 'bool') {
                    throw new FormulaException('الوسيط الأول في IF يجب أن يكون مقارنة مثل [أ] > 0.', 'type');
                }
                self::need(self::typeOf($args[1], $scope), 'number', 'الدالة IF');
                self::need(self::typeOf($args[2], $scope), 'number', 'الدالة IF');

                return 'number';
        }
        throw new FormulaException("دالة غير مدعومة: {$f}.", 'unsupported_function');
    }

    /** Referenced keys in first-use order (duplicates removed). */
    public static function references(array $n): array
    {
        $out = [];
        $walk = function (array $node) use (&$walk, &$out): void {
            switch ($node['t']) {
                case 'ref':
                    $out[$node['k']] = $node['src'];
                    break;
                case 'neg':
                case 'pct':
                    $walk($node['a']);
                    break;
                case 'bin':
                case 'cmp':
                    $walk($node['l']);
                    $walk($node['r']);
                    break;
                case 'call':
                    foreach ($node['args'] as $a) {
                        $walk($a);
                    }
                    break;
            }
        };
        $walk($n);

        return array_keys($out);
    }

    // ── printing ──────────────────────────────────────────────────────────

    /** Stored form: stable keys, e.g. {fixed_salary} * {insurance_rate}. */
    public static function canonical(array $n): string
    {
        return self::print($n, fn (string $k) => '{'.$k.'}');
    }

    /** Editing form: display names, e.g. [الأجر المقطوع] * [نسبة التأمينات]. */
    public static function display(array $n, FormulaScope $scope): string
    {
        return self::print($n, fn (string $k) => '['.($scope->byKey($k)['label'] ?? $k).']');
    }

    /** @param callable(string):string $ref */
    public static function print(array $n, callable $ref, int $parent = 0): string
    {
        $wrap = fn (string $s, int $own) => $own < $parent ? "({$s})" : $s;
        switch ($n['t']) {
            case 'num':
                return $n['v'];
            case 'str':
                return '"'.$n['v'].'"';
            case 'ref':
                return $ref($n['k']);
            case 'neg':
                return '-'.self::print($n['a'], $ref, 5);
            case 'pct':
                return self::print($n['a'], $ref, 6).'%';
            case 'bin':
                $own = in_array($n['op'], ['+', '-'], true) ? 3 : 4;
                $rightParent = $own + 1;

                return $wrap(self::print($n['l'], $ref, $own).' '.$n['op'].' '.self::print($n['r'], $ref, $rightParent), $own);
            case 'cmp':
                return $wrap(self::print($n['l'], $ref, 3).' '.$n['op'].' '.self::print($n['r'], $ref, 3), 2);
            case 'call':
                return $n['f'].'('.implode(', ', array_map(fn ($a) => self::print($a, $ref, 0), $n['args'])).')';
        }

        return '';
    }
}
