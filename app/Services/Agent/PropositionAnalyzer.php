<?php

namespace App\Services\Agent;

use App\Services\Wiki\TextTokenizer;

/**
 * 把一段文本读成可确定性比较的结构：数量事实、边界方向、限定词、命题子句。
 *
 * 这些原语有两个使用者——答案对证据的接地校验，以及证据之间的冲突判定。
 * 两处必须用同一套读法，否则会各自漂移出一套「什么算同一件事」。
 * 本类只负责怎么读，不判断什么算失败。
 */
class PropositionAnalyzer
{
    /**
     * 单位 => 单位族。同族单位可以互相改写，跨族不行。
     *
     * 「三天」与「3 个自然日」是同一个事实，「5 个工作日」与「5 天」不是——
     * 前者是合法改写，后者会改变结论。不在表内的数字不参与校验，
     * 宁可漏检也不误判。
     *
     * 这是单位表，不是主题词表：单位是封闭且领域中性的集合，
     * 不会随知识库增长而变成一堆特例。
     */
    private const UNIT_FAMILIES = [
        '自然日' => 'day', '天' => 'day', '日' => 'day',
        '工作日' => 'workday',
        '小时' => 'hour', '钟头' => 'hour',
        '分钟' => 'minute',
        '秒' => 'second',
        '星期' => 'week', '周' => 'week',
        '月' => 'month',
        '年' => 'year',
        '人民币' => 'cny', '元' => 'cny',
        '美元' => 'usd', '美金' => 'usd',
        '像素' => 'pixel', 'px' => 'pixel',
        '字节' => 'byte',
        'gb' => 'gb', 'mb' => 'mb', 'kb' => 'kb', 'tb' => 'tb',
        'percent' => 'percent', 'per cent' => 'percent', 'pct' => 'percent',
        '次' => 'times', '个' => 'items', '项' => 'entries', '条' => 'records',
        '份' => 'copies', '页' => 'pages', '章' => 'chapters', '节' => 'sections',
        '步' => 'steps', '人' => 'people', '台' => 'devices', '张' => 'sheets',
        '行' => 'lines', '字' => 'characters',

        // 原始资料可能是英文而 Wiki 是中文编译稿，跨语言引用必须能对上。
        'calendar days' => 'day', 'calendar day' => 'day', 'days' => 'day', 'day' => 'day',
        'business days' => 'workday', 'business day' => 'workday',
        'working days' => 'workday', 'working day' => 'workday',
        'workdays' => 'workday', 'workday' => 'workday',
        'hours' => 'hour', 'hour' => 'hour',
        'minutes' => 'minute', 'minute' => 'minute',
        'seconds' => 'second', 'second' => 'second',
        'weeks' => 'week', 'week' => 'week',
        'months' => 'month', 'month' => 'month',
        'years' => 'year', 'year' => 'year',
        'pages' => 'pages', 'page' => 'pages',
        'items' => 'items', 'item' => 'items',
        'steps' => 'steps', 'step' => 'steps',
        'lines' => 'lines', 'line' => 'lines',
        'times' => 'times', 'time' => 'times',
        'people' => 'people', 'person' => 'people',
    ];

    /**
     * 会实质改变结论的限定词 => 语义族。答案写出的限定必须在证据里有依据，
     * 否则就是模型自己加的条件。
     *
     * 与单位表同理：这是封闭的语法性限定词集合，不是主题词表。
     * 原始资料与答案常常不同语言，两侧都要能识别。
     */
    private const CONDITION_FAMILIES = [
        '仅限' => 'only', '只限' => 'only', '只有' => 'only', '仅' => 'only', '只' => 'only',
        'only' => 'only', 'solely' => 'only',

        '首次' => 'first_time', '第一次' => 'first_time', '初次' => 'first_time',
        'first time' => 'first_time', 'initial' => 'first_time',

        '每日' => 'per_period', '每天' => 'per_period', '每周' => 'per_period',
        '每月' => 'per_period', '每年' => 'per_period', '每次' => 'per_period',
        'per day' => 'per_period', 'daily' => 'per_period', 'per week' => 'per_period',
        'weekly' => 'per_period', 'per month' => 'per_period', 'monthly' => 'per_period',
        'per year' => 'per_period', 'annually' => 'per_period', 'each time' => 'per_period',

        '累计' => 'cumulative', '合计' => 'cumulative', '总共' => 'cumulative',
        'cumulative' => 'cumulative', 'in total' => 'cumulative', 'combined' => 'cumulative',

        '除非' => 'conditional', '仅在' => 'conditional', '当且仅当' => 'conditional',
        'unless' => 'conditional', 'only if' => 'conditional', 'only when' => 'conditional',
    ];

    /**
     * 数量的边界方向。把「至少 3 天」写成「最多 3 天」会改变结论，
     * 凭空加上「不超过」同样会。
     */
    private const BOUND_MARKERS = [
        '至少' => 'minimum', '最少' => 'minimum', '不少于' => 'minimum', '不低于' => 'minimum',
        '超过' => 'minimum', '以上' => 'minimum', '起' => 'minimum',
        'at least' => 'minimum', 'no less than' => 'minimum', 'minimum' => 'minimum',
        'more than' => 'minimum', 'or more' => 'minimum', 'exceeds' => 'minimum',
        'exceeding' => 'minimum', 'greater than' => 'minimum', 'above' => 'minimum',

        '至多' => 'maximum', '最多' => 'maximum', '不超过' => 'maximum', '不多于' => 'maximum',
        '不高于' => 'maximum', '以内' => 'maximum', '以下' => 'maximum', '不足' => 'maximum',
        'at most' => 'maximum', 'no more than' => 'maximum', 'up to' => 'maximum',
        'maximum' => 'maximum', 'less than' => 'maximum', 'within' => 'maximum',
        'fewer than' => 'maximum', 'below' => 'maximum', 'under' => 'maximum',
    ];

    /** 判定边界方向时，数字前后各看多少字节。 */
    private const BOUND_LOOKBEHIND = 40;

    private const BOUND_LOOKAHEAD = 20;

    private const NEGATIONS = [
        '不得', '不能', '不可以', '不可', '不会', '不需要', '不用', '不是', '不',
        '没有', '没', '无法', '无需', '无', '未', '非', '禁止', '严禁',
    ];

    /**
     * 英文否定词必须按词边界匹配：按子串数会把 note、know、notice
     * 里的 no / not 也算成否定。
     */
    private const ASCII_NEGATION_PATTERN = '/\b(?:cannot|can\'t|never|without|not|no)\b/iu';

    /** 这些词以「不/无」开头但不表示否定。 */
    private const NEGATION_EXCEPTIONS = ['不但', '不仅', '不止', '无论', '不管', '不过', '无非'];

    /** 命题被认为是「同一件事」所需的片段重合比例。 */
    private const SAME_PROPOSITION_OVERLAP = 0.75;

    private const MIN_PROPOSITION_TOKENS = 3;

    public function __construct(private readonly TextTokenizer $tokenizer) {}

    /**
     * 提取可确定性核对的数量事实：比例、数量加单位。
     *
     * 键用于比较（数值 + 单位族），值用于给人看的失败说明。
     *
     * @return array<string, string>
     */
    public function quantityFacts(string $text): array
    {
        $facts = [];

        $normalized = $this->normalize($text);

        if (preg_match_all('/(\d+(?:\.\d+)?)\s*%/u', $normalized, $matches) !== false) {
            foreach ($matches[1] as $value) {
                $facts[$value.':percent'] = $value.'%';
            }
        }

        if (preg_match_all('/(\d+(?:\.\d+)?)\s*个?\s*('.$this->unitPattern().')/u', $normalized, $matches) !== false) {
            foreach ($matches[1] as $index => $value) {
                $unit = $matches[2][$index];
                $facts[$value.':'.self::UNIT_FAMILIES[$unit]] = $value.$unit;
            }
        }

        return $facts;
    }

    /** @return list<string> */
    public function urls(string $text): array
    {
        if (preg_match_all('#https?://[^\s，。、；：）)】」』"\'<>]+#iu', $text, $matches) === false) {
            return [];
        }

        return array_values(array_unique(array_map(
            static fn (string $url): string => mb_strtolower(rtrim($url, '.,;/')),
            $matches[0],
        )));
    }

    /**
     * 长单位排在前面，"3 个自然日" 才不会被截成 "3 个"。
     */
    private function unitPattern(): string
    {
        $units = array_keys(self::UNIT_FAMILIES);
        usort($units, static fn (string $left, string $right): int => mb_strlen($right) <=> mb_strlen($left));

        return implode('|', array_map(
            static fn (string $unit): string => preg_quote($unit, '/'),
            $units,
        ));
    }

    /**
     * 把中文数字与全角数字统一成半角阿拉伯数字，使「三天」「3 天」「３天」等价。
     */
    private function normalize(string $text): string
    {
        $text = mb_strtolower(str_replace(
            ['０', '１', '２', '３', '４', '５', '６', '７', '８', '９', '％', '．'],
            ['0', '1', '2', '3', '4', '5', '6', '7', '8', '9', '%', '.'],
            $text,
        ));

        $text = preg_replace_callback(
            '/百分之([〇零一二三四五六七八九十百两]+)/u',
            fn (array $match): string => $this->chineseNumber($match[1]).'%',
            $text,
        ) ?? $text;

        return preg_replace_callback(
            '/([〇零一二三四五六七八九十百两]+)/u',
            fn (array $match): string => $this->chineseNumber($match[1]),
            $text,
        ) ?? $text;
    }

    /**
     * 只处理百以内的常见写法；无法确定的原样返回，
     * 让它退化成必须逐字出现在证据里。
     */
    private function chineseNumber(string $text): string
    {
        $digits = ['〇' => 0, '零' => 0, '一' => 1, '二' => 2, '两' => 2, '三' => 3, '四' => 4,
            '五' => 5, '六' => 6, '七' => 7, '八' => 8, '九' => 9];

        $characters = mb_str_split($text);
        if (count($characters) === 1 && isset($digits[$characters[0]])) {
            return (string) $digits[$characters[0]];
        }

        $tens = array_search('十', $characters, true);
        if ($tens === false || count($characters) > 3) {
            return $text;
        }

        $high = $tens === 0 ? 1 : ($digits[$characters[0]] ?? null);
        $low = $tens === count($characters) - 1 ? 0 : ($digits[$characters[$tens + 1]] ?? null);

        return $high === null || $low === null ? $text : (string) ($high * 10 + $low);
    }

    /**
     * @return array<string, list<string>> 事实键 => 边界方向
     */
    public function bounds(string $text): array
    {
        $normalized = $this->normalize($text);
        $pattern = '/(\d+(?:\.\d+)?)\s*个?\s*('.$this->unitPattern().'|%)/u';

        if (preg_match_all($pattern, $normalized, $matches, PREG_OFFSET_CAPTURE) === false) {
            return [];
        }

        $bounds = [];
        foreach ($matches[0] as $index => $match) {
            [$text0, $offset] = $match;
            $unit = $matches[2][$index][0];
            $key = $matches[1][$index][0].':'.(self::UNIT_FAMILIES[$unit] ?? 'percent');

            $window = $this->window($normalized, $offset, strlen($text0));
            foreach (self::BOUND_MARKERS as $marker => $direction) {
                if (mb_strpos($window, $marker) !== false) {
                    $bounds[$key][] = $direction;
                }
            }
            $bounds[$key] = array_values(array_unique($bounds[$key] ?? []));
        }

        return $bounds;
    }

    /**
     * 判定窗口限制在数字所在的子句内。
     *
     * 否则「日志保留 30 天，最多导出 10 份」里挂在 10 份上的「最多」
     * 会被算到 30 天头上。
     */
    private function window(string $text, int $offset, int $length): string
    {
        $delimiter = '/[，。；：！？、\n,;!?]/u';

        $before = substr($text, 0, $offset);
        if (preg_match_all($delimiter, $before, $matches, PREG_OFFSET_CAPTURE) > 0) {
            $last = $matches[0][count($matches[0]) - 1];
            $before = substr($before, $last[1] + strlen($last[0]));
        }
        $before = substr($before, -self::BOUND_LOOKBEHIND);

        $after = substr($text, $offset + $length);
        if (preg_match($delimiter, $after, $matches, PREG_OFFSET_CAPTURE) === 1) {
            $after = substr($after, 0, $matches[0][1]);
        }
        $after = substr($after, 0, self::BOUND_LOOKAHEAD);

        $window = $before.substr($text, $offset, $length).$after;

        return preg_replace('/^[\x80-\xBF]+/', '', $window) ?? $window;
    }

    /**
     * @return array<string, string> 语义族 => 命中的词
     */
    public function conditions(string $text): array
    {
        $normalized = mb_strtolower($text);

        $found = [];
        foreach (self::CONDITION_FAMILIES as $marker => $family) {
            if (isset($found[$family])) {
                continue;
            }
            if (mb_strpos($normalized, $marker) !== false) {
                $found[$family] = $marker;
            }
        }

        return $found;
    }

    /**
     * 拆成命题子句。
     *
     * tokens 取自「剥掉否定词与数字」之后的骨架：判断两句是否在说同一件事，
     * 不应该被肯否和具体数值影响——「保留 30 天」与「保留 90 天」是同一个命题
     * 的两种说法，正因为如此它们的数值分歧才构成冲突。
     *
     * @return list<array{text: string, polarity: bool, tokens: list<string>, facts: array<string, string>}>
     */
    public function clauses(string $text): array
    {
        $parts = preg_split('/[。！？；，\n!?;,]+/u', $text, -1, PREG_SPLIT_NO_EMPTY) ?: [];

        $clauses = [];
        foreach ($parts as $part) {
            $part = trim($part);
            if ($part === '') {
                continue;
            }

            $skeleton = preg_replace('/\d+(?:\.\d+)?/u', '', $this->normalize($this->stripNegations($part))) ?? '';
            $tokens = $this->tokenizer->queryTokens($skeleton);
            if (count($tokens) < self::MIN_PROPOSITION_TOKENS) {
                continue;
            }

            $clauses[] = [
                'text' => $part,
                'polarity' => $this->isAffirmative($part),
                'tokens' => $tokens,
                'facts' => $this->quantityFacts($part),
            ];
        }

        return $clauses;
    }

    /**
     * 两个子句是否就同一单位给出了不同数值。
     *
     * @param  array{facts: array<string, string>}  $left
     * @param  array{facts: array<string, string>}  $right
     */
    public function statesDifferentQuantities(array $left, array $right): bool
    {
        $byFamily = static function (array $facts): array {
            $grouped = [];
            foreach (array_keys($facts) as $key) {
                [$value, $family] = array_pad(explode(':', $key, 2), 2, '');
                $grouped[$family][$value] = true;
            }

            return $grouped;
        };

        $leftFamilies = $byFamily($left['facts']);
        $rightFamilies = $byFamily($right['facts']);

        foreach ($leftFamilies as $family => $values) {
            if (! isset($rightFamilies[$family])) {
                continue;
            }
            if (array_keys($values) !== array_keys($rightFamilies[$family])) {
                return true;
            }
        }

        return false;
    }

    private function isAffirmative(string $text): bool
    {
        $stripped = str_replace(self::NEGATION_EXCEPTIONS, '', mb_strtolower($text));

        $count = preg_match_all(self::ASCII_NEGATION_PATTERN, $stripped) ?: 0;
        $stripped = preg_replace(self::ASCII_NEGATION_PATTERN, '', $stripped) ?? $stripped;

        foreach (self::NEGATIONS as $negation) {
            $occurrences = mb_substr_count($stripped, $negation);
            if ($occurrences > 0) {
                $count += $occurrences;
                $stripped = str_replace($negation, '', $stripped);
            }
        }

        return $count % 2 === 0;
    }

    private function stripNegations(string $text): string
    {
        $text = str_replace(self::NEGATION_EXCEPTIONS, '', mb_strtolower($text));
        $text = preg_replace(self::ASCII_NEGATION_PATTERN, '', $text) ?? $text;

        return str_replace(self::NEGATIONS, '', $text);
    }

    /**
     * @param  list<string>  $left
     * @param  list<string>  $right
     */
    public function samePropositions(array $left, array $right): bool
    {
        $shared = count(array_intersect($left, $right));
        $smaller = min(count($left), count($right));

        return $smaller > 0 && $shared / $smaller >= self::SAME_PROPOSITION_OVERLAP;
    }

    public function sameScript(string $left, string $right): bool
    {
        $hasHan = static fn (string $text): bool => preg_match('/\p{Han}/u', $text) === 1;

        return $hasHan($left) === $hasHan($right);
    }
}
