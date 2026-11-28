<?php

declare(strict_types=1);

namespace PERCS31\Helpers;

use PHP_CodeSniffer\Files\File;

/**
 * One part of an expression, as PERCS31.Formatting.ExpressionFormat reads it:
 * - An operand with no binary operator outside its brackets, such as `$a`, `foo($b)` or `-$c`.
 * - A group: an operand in grouping parentheses, such as `($a && $b)` or `!($a || $b)`.
 * - A level: operands joined by the operators of one precedence, such as `$a || $b || $c`. An operand of a level can
 *   itself be a level of operators that bind more tightly, as `$b && $c` is in `$a || $b && $c`.
 * - A call: a call's argument list, or an array's values, written on one line, such as `foo($a, $b)` or `[$a, $b]`,
 *   whose children are the arguments.
 * - A list: a `match` arm's conditions, when it has several, such as `1, 2` in `1, 2 => 'small'`, whose children are
 *   the conditions. It's the key of the arm's pair.
 * - A ternary: a condition, `?`, a value, `:` and another value, whose children are the condition and the values, and
 *   whose operators are the `?` and the `:`. A short ternary, `$a ?: $b`, has the condition and one value.
 * - A chain: an object and two or more links after `->` or `?->`, such as `$query->where($a)->get()`, whose children
 *   are the object and the links, and whose operators are the arrows.
 * - A body: a closure, `match` or anonymous class, which always spans lines. Its body isn't laid out here; only where
 *   it starts, after its first line up to the `{`, which must be on one line.
 */
readonly class ExpressionNode
{
    public const string OPERAND = 'operand';

    public const string GROUP = 'group';

    public const string LEVEL = 'level';

    public const string CALL = 'call';

    public const string BODY = 'body';

    public const string LIST = 'list';

    public const string TERNARY = 'ternary';

    public const string CHAIN = 'chain';

    /**
     * A call or array on one line, from findLayout().
     */
    public const string FLAT = 'flat';

    /**
     * A call or array chopped down, one argument per line, from findLayout().
     */
    public const string CHOPPED = 'chopped';

    /**
     * A call or array split in some other way, from findLayout().
     */
    public const string OTHER = 'other';

    /**
     * The length given to a body, which never fits on one line.
     */
    public const int BODY_LENGTH = 1000000;

    /**
     * Constructor.
     *
     * @param string $kind OPERAND, GROUP, LEVEL, CALL, BODY, LIST, TERNARY or CHAIN.
     * @param int $start The position of the first token.
     * @param int $end The position of the last token.
     * @param int $flatLength The length of the node written on one line.
     * @param list<ExpressionNode> $children A level's operands, a group's contents, or a call's arguments.
     * @param list<int> $operators The positions of a level's operators, one between each pair of operands, or a
     * ternary's `?` and `:`.
     * @param ?int $opener The position of a group's or call's opening parenthesis, or an array's opening bracket.
     * @param ?int $closer The position of a group's or call's closing parenthesis, or an array's closing bracket.
     * @param list<int> $separators The positions of a call's or list's commas, one between each pair of children.
     * @param ?int $trailingComma The position of a call's or list's comma after its last child, if it has one.
     * @param bool $isArray Whether a call is an array's values, which are always split one per line, never hugging.
     * @param int $headLength The length of a body's first line, up to and including its `{`.
     * @param bool $isMatch Whether a body is a `match`, which can be hugged, unlike a closure or anonymous class.
     * @param array<int, string> $calleeGaps The whitespace before a call's opening bracket that spans lines, by
     * position, with what it becomes on one line, as in `new` then `Foo()`.
     */
    public function __construct(
        public string $kind,
        public int $start,
        public int $end,
        public int $flatLength,
        public array $children = [],
        public array $operators = [],
        public ?int $opener = null,
        public ?int $closer = null,
        public array $separators = [],
        public ?int $trailingComma = null,
        public bool $isArray = false,
        public int $headLength = 0,
        public bool $isMatch = false,
        public array $calleeGaps = [],
    ) {}

    /**
     * Get the length of what comes before a call's opening bracket, such as `foo` or `$this->bar`.
     *
     * @return int The length.
     */
    public function calleeLength(): int
    {
        $arguments = array_sum(array_map(static fn(self $child): int => $child->flatLength, $this->children));

        return $this->flatLength - $arguments - 2 * max(0, count($this->children) - 1) - 2;
    }

    /**
     * Get the length of a call's or body's first line when it's split: up to and including its opening bracket, or its
     * body's `{`. A call with no arguments can't be split, so its first line is all of it.
     *
     * @return int The length.
     */
    public function firstLineLength(): int
    {
        return match (true) {
            $this->kind === self::BODY => $this->headLength,
            $this->children === [] => $this->flatLength,
            default => $this->calleeLength() + 1,
        };
    }

    /**
     * Get the length of the first piece, which can't be split from the start of its line: a level's, ternary's or
     * chain's first part's, a call's name and opening bracket, or a body's first line.
     *
     * @return int The length.
     */
    public function leadLength(): int
    {
        return match ($this->kind) {
            self::LEVEL, self::TERNARY, self::CHAIN => $this->children[0]->leadLength(),
            self::GROUP => $this->flatLength - $this->children[0]->flatLength - 1 + $this->children[0]->leadLength(),
            self::CALL, self::BODY => $this->firstLineLength(),
            default => $this->flatLength,
        };
    }

    /**
     * Find how a call or array is laid out now.
     *
     * @param File $phpcsFile The file being scanned.
     * @return int|string FLAT if it's on one line, CHOPPED if each argument starts a line and the closer has one of its
     * own, the index of the one argument that spans lines with the rest on the call's lines, or OTHER.
     */
    public function findLayout(File $phpcsFile): int|string
    {
        $tokens = $phpcsFile->getTokens();
        if ($this->children === []) {
            return $tokens[(int) $this->opener]['line'] === $tokens[(int) $this->closer]['line']
                ? self::FLAT
                : self::OTHER;
        }

        $last = $this->children[count($this->children) - 1];
        $gaps = [[(int) $this->opener, $this->children[0]->start]];
        foreach ($this->separators as $index => $comma) {
            $gaps[] = [$comma, $this->children[$index + 1]->start];
        }

        $gaps[] = [$this->trailingComma ?? $last->end, (int) $this->closer];
        $isBroken = static fn(array $gap): bool => $tokens[$gap[0]]['line'] !== $tokens[$gap[1]]['line'];
        $broken = array_map($isBroken, $gaps);
        if (!in_array(false, $broken, true)) {
            return self::CHOPPED;
        }

        if (in_array(true, $broken, true)) {
            return self::OTHER;
        }

        $spanning = array_keys(array_filter(
            $this->children,
            static fn(self $child): bool => $tokens[$child->start]['line'] !== $tokens[$child->end]['line'],
        ));

        return match (count($spanning)) {
            0 => self::FLAT,
            1 => $spanning[0],
            default => self::OTHER,
        };
    }
}
