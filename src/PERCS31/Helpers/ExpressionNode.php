<?php

declare(strict_types=1);

namespace PERCS31\Helpers;

/**
 * One part of an expression, as PERCS31.Formatting.ExpressionFormat reads it:
 * - An operand with no binary operator outside its brackets, such as `$a`, `foo($b)` or `-$c`.
 * - A group: an operand in grouping parentheses, such as `($a && $b)` or `!($a || $b)`.
 * - A level: operands joined by the operators of one precedence, such as `$a || $b || $c`. An operand of a level can
 *   itself be a level of operators that bind more tightly, as `$b && $c` is in `$a || $b && $c`.
 * - A call: a call's argument list, or an array's values, written on one line, such as `foo($a, $b)` or `[$a, $b]`,
 *   whose children are the arguments.
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

    /**
     * The length given to a body, which never fits on one line.
     */
    public const int BODY_LENGTH = 1000000;

    /**
     * Constructor.
     *
     * @param string $kind OPERAND, GROUP, LEVEL, CALL or BODY.
     * @param int $start The position of the first token.
     * @param int $end The position of the last token.
     * @param int $flatLength The length of the node written on one line.
     * @param list<ExpressionNode> $children A level's operands, a group's contents, or a call's arguments.
     * @param list<int> $operators The positions of a level's operators, one between each pair of operands.
     * @param ?int $opener The position of a group's or call's opening parenthesis, or an array's opening bracket.
     * @param ?int $closer The position of a group's or call's closing parenthesis, or an array's closing bracket.
     * @param list<int> $separators The positions of a call's commas, one between each pair of arguments.
     * @param ?int $trailingComma The position of a call's comma after its last argument, if it has one.
     * @param bool $isArray Whether a call is an array's values, which are always split one per line, never hugged.
     * @param int $headLength The length of a body's first line, up to and including its `{`.
     * @param bool $isClosure Whether a body is a closure's, which can be hugged, unlike a `match` or anonymous class.
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
        public bool $isClosure = false,
    ) {}
}
