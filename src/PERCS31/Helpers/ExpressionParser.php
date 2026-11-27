<?php

declare(strict_types=1);

namespace PERCS31\Helpers;

use PHP_CodeSniffer\Files\File;
use PHP_CodeSniffer\Util\Tokens;

/**
 * Helper class for parsing an expression's tokens into a tree of ExpressionNode objects: operators by precedence,
 * groups, calls and arrays, bodies, chains, ternaries, pairs, assignments and lists. ExpressionHelper finds where an
 * expression starts and ends.
 */
class ExpressionParser
{
    /**
     * Prefix operators that can come before an operand's grouping parentheses, besides casts.
     *
     * @var list<int|string>
     */
    private const array PREFIXES = [T_BOOLEAN_NOT, T_BITWISE_NOT, T_ASPERAND, T_MINUS, T_PLUS];

    /**
     * Parse part of an expression into a tree of its operands, groups and levels of operators.
     *
     * @param File $phpcsFile The file being scanned.
     * @param int $start The position of the first token.
     * @param int $end The position of the last token.
     * @return ?ExpressionNode The node, or null if an operand has a line break inside it.
     */
    public static function parse(File $phpcsFile, int $start, int $end): ?ExpressionNode
    {
        $tokens = $phpcsFile->getTokens();
        $arrowFunction = self::parseArrowFunction($phpcsFile, $start, $end);
        if ($arrowFunction !== false) {
            return $arrowFunction;
        }

        $echo = self::parseEchoList($phpcsFile, $start, $end);
        if ($echo !== false) {
            return $echo;
        }

        $assignment = self::findAssignment($phpcsFile, $start, $end);
        if ($assignment !== null) {
            return self::parseAssignment($phpcsFile, $start, $end, $assignment);
        }

        $arm = self::findArmArrow($phpcsFile, $start, $end);
        if ($arm !== null) {
            return self::parseArm($phpcsFile, $start, $end, $arm);
        }

        $questions = self::findTernaryTokens($phpcsFile, $start, $end);
        if ($questions !== []) {
            return count($questions) === 2 ? self::parseTernary($phpcsFile, $start, $end, ...$questions) : null;
        }

        $operators = [];
        for ($i = $start; $i <= $end; $i++) {
            // An arrow function runs to the end, and its body's operators are its own.
            if ($tokens[$i]['code'] === T_FN) {
                break;
            }

            if ($i !== $start && ExpressionHelper::isBinary($phpcsFile, $i)) {
                $operators[] = $i;
            }

            $closer = TokenHelper::findCloser($tokens[$i]);
            if ($closer !== null && $closer > $i) {
                $i = $closer;
            }
        }

        return $operators === []
            ? self::parseOperand($phpcsFile, $start, $end)
            : self::parseLevel($phpcsFile, $start, $end, $operators);
    }

    /**
     * Parse an `echo`'s values, when it has more than one, as a list, like a call's arguments without brackets.
     *
     * @param File $phpcsFile The file being scanned.
     * @param int $start The position of the first value's first token.
     * @param int $end The position of the last value's last token.
     * @return ExpressionNode|false|null The list, false if the expression isn't one, or null if it can't be read.
     */
    private static function parseEchoList(File $phpcsFile, int $start, int $end): ExpressionNode|false|null
    {
        $tokens = $phpcsFile->getTokens();
        $echo = $phpcsFile->findPrevious(Tokens::EMPTY_TOKENS, ($start - 1), null, true);
        if ($echo === false || $tokens[$echo]['code'] !== T_ECHO) {
            return false;
        }

        $commas = [];
        for ($i = $start; $i <= $end; $i++) {
            if ($tokens[$i]['code'] === T_COMMA) {
                $commas[] = $i;
            }

            $i = ExpressionHelper::findNestedEnd($tokens[$i], $i);
        }

        if ($commas === []) {
            return false;
        }

        $children = [];
        $valueStart = $start;
        foreach ([...$commas, $end + 1] as $boundary) {
            $first = $phpcsFile->findNext(T_WHITESPACE, $valueStart, $boundary, true);
            $last = $phpcsFile->findPrevious(T_WHITESPACE, ($boundary - 1), $valueStart, true);
            $child = $first === false || $last === false ? null : self::parse($phpcsFile, $first, $last);
            if ($child === null) {
                return null;
            }

            $children[] = $child;
            $valueStart = $boundary + 1;
        }

        $flatLength = array_sum(array_map(static fn(ExpressionNode $child): int => $child->flatLength, $children))
            + 2 * count($commas);

        return new ExpressionNode(ExpressionNode::LIST, $start, $end, $flatLength, $children, separators: $commas);
    }

    /**
     * Find an assignment's operator outside any brackets, the first if there are several, as in `$a = $b = 1`.
     *
     * @param File $phpcsFile The file being scanned.
     * @param int $start The position of the expression's first token.
     * @param int $end The position of its last token.
     * @return ?int The position of the operator, or null if there's none.
     */
    private static function findAssignment(File $phpcsFile, int $start, int $end): ?int
    {
        $tokens = $phpcsFile->getTokens();
        for ($i = $start; $i <= $end; $i++) {
            if ($tokens[$i]['code'] === T_FN) {
                return null;
            }

            if (ExpressionHelper::isAssignmentPair($phpcsFile, $i)) {
                return $i;
            }

            $i = ExpressionHelper::findNestedEnd($tokens[$i], $i);
        }

        return null;
    }

    /**
     * Parse an assignment, as a pair: its target is the key, and its value the value.
     *
     * @param File $phpcsFile The file being scanned.
     * @param int $start The position of the target's first token.
     * @param int $end The position of the value's last token.
     * @param int $operator The position of the assignment operator.
     * @return ?ExpressionNode The assignment, or null if it can't be read.
     */
    private static function parseAssignment(File $phpcsFile, int $start, int $end, int $operator): ?ExpressionNode
    {
        $targetEnd = (int) $phpcsFile->findPrevious(T_WHITESPACE, ($operator - 1), $start, true);
        $valueStart = $phpcsFile->findNext(T_WHITESPACE, ($operator + 1), ($end + 1), true);
        $target = self::parse($phpcsFile, $start, $targetEnd);
        $value = $valueStart === false ? null : self::parse($phpcsFile, $valueStart, $end);
        if ($target === null || $value === null) {
            return null;
        }

        $length = mb_strlen($phpcsFile->getTokens()[$operator]['content']);

        return new ExpressionNode(
            ExpressionNode::LEVEL,
            $start,
            $end,
            $target->flatLength + $length + 2 + $value->flatLength,
            [$target, $value],
            [$operator],
        );
    }

    /**
     * Parse a chain: an object, then two or more links after `->` or `?->` outside any brackets. A static call or
     * property before the first arrow is part of the object, and a `::` or an index after a link is part of the link.
     *
     * @param File $phpcsFile The file being scanned.
     * @param int $start The position of the operand's first token.
     * @param int $end The position of its last token.
     * @return ExpressionNode|false|null The chain, false if the operand isn't one, or null if it can't be read.
     */
    private static function parseChain(File $phpcsFile, int $start, int $end): ExpressionNode|false|null
    {
        $tokens = $phpcsFile->getTokens();
        $arrows = [];
        for ($i = $start; $i <= $end; $i++) {
            if (in_array($tokens[$i]['code'], [T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR], true)) {
                $arrows[] = $i;
            }

            $i = ExpressionHelper::findNestedEnd($tokens[$i], $i);
        }

        if (count($arrows) < 2) {
            return false;
        }

        $headEnd = $phpcsFile->findPrevious(T_WHITESPACE, ($arrows[0] - 1), $start, true);
        $head = $headEnd === false ? null : self::parse($phpcsFile, $start, $headEnd);
        if ($head === null) {
            return null;
        }

        $children = [$head];
        $flatLength = $head->flatLength;
        foreach ($arrows as $index => $arrow) {
            $linkStart = $phpcsFile->findNext(T_WHITESPACE, ($arrow + 1), ($end + 1), true);
            $linkEnd = $phpcsFile->findPrevious(T_WHITESPACE, (($arrows[$index + 1] ?? ($end + 1)) - 1), $arrow, true);
            $link = $linkStart === false || $linkEnd === false || $linkEnd < $linkStart
                ? null
                : self::parse($phpcsFile, $linkStart, $linkEnd);
            if ($link === null) {
                return null;
            }

            $children[] = $link;
            $flatLength += mb_strlen($tokens[$arrow]['content']) + $link->flatLength;
        }

        return new ExpressionNode(ExpressionNode::CHAIN, $start, $end, $flatLength, $children, $arrows);
    }

    /**
     * Parse an arrow function, as a pair: its signature, which must be on one line, is the key, and its body the value.
     *
     * @param File $phpcsFile The file being scanned.
     * @param int $start The position of the expression's first token.
     * @param int $end The position of its last token.
     * @return ExpressionNode|false|null The arrow function, false if the expression isn't one, or null if it can't be
     * read.
     */
    private static function parseArrowFunction(File $phpcsFile, int $start, int $end): ExpressionNode|false|null
    {
        $tokens = $phpcsFile->getTokens();
        $fn = $tokens[$start]['code'] === T_STATIC
            ? $phpcsFile->findNext(Tokens::EMPTY_TOKENS, ($start + 1), $end, true)
            : $start;
        $arrow = $fn === false ? null : ($tokens[$fn]['scope_opener'] ?? null);
        $closer = $fn === false ? null : ($tokens[$fn]['scope_closer'] ?? null);
        if ($fn === false || $tokens[$fn]['code'] !== T_FN || !is_int($arrow) || !is_int($closer)) {
            return false;
        }

        $bodyEnd = $phpcsFile->findPrevious(Tokens::EMPTY_TOKENS, ($closer - 1), $arrow, true);
        $bodyStart = $phpcsFile->findNext(T_WHITESPACE, ($arrow + 1), ($end + 1), true);
        $signatureEnd = (int) $phpcsFile->findPrevious(T_WHITESPACE, ($arrow - 1), $start, true);
        $signature = $phpcsFile->getTokensAsString($start, ($signatureEnd - $start + 1));
        if ($bodyEnd !== $end) {
            return false;
        }

        $body = $bodyStart === false ? null : self::parse($phpcsFile, $bodyStart, $end);
        if ($body === null || str_contains($signature, "\n")) {
            return null;
        }

        $key = new ExpressionNode(ExpressionNode::OPERAND, $start, $signatureEnd, mb_strlen($signature));

        return new ExpressionNode(
            ExpressionNode::LEVEL,
            $start,
            $end,
            $key->flatLength + 4 + $body->flatLength,
            [$key, $body],
            [$arrow],
        );
    }

    /**
     * Find a ternary's `?` and `:` outside any brackets.
     *
     * @param File $phpcsFile The file being scanned.
     * @param int $start The position of the expression's first token.
     * @param int $end The position of its last token.
     * @return list<int> The positions of the `?` and the `:`, nothing if there's no ternary, or anything else, such
     * as a chain of two, which isn't read.
     */
    private static function findTernaryTokens(File $phpcsFile, int $start, int $end): array
    {
        $tokens = $phpcsFile->getTokens();
        $found = [];
        for ($i = $start; $i <= $end; $i++) {
            if ($tokens[$i]['code'] === T_FN) {
                break;
            }

            // A pair's `=>`, or an assignment's operator, binds more loosely than a ternary, so the ternary is in the
            // pair's value.
            $isArrow = in_array($tokens[$i]['code'], [T_DOUBLE_ARROW, T_MATCH_ARROW], true)
                && ExpressionHelper::isArrowOperator($phpcsFile, $i);
            if ($isArrow || ExpressionHelper::isAssignmentPair($phpcsFile, $i)) {
                return [];
            }

            if (in_array($tokens[$i]['code'], [T_INLINE_THEN, T_INLINE_ELSE], true)) {
                $found[] = $i;
            }

            $i = ExpressionHelper::findNestedEnd($tokens[$i], $i);
        }

        $isPair = count($found) === 2 && $tokens[$found[0]]['code'] === T_INLINE_THEN;

        return $found === [] || $isPair ? $found : [-1];
    }

    /**
     * Parse a ternary into its condition and values.
     *
     * @param File $phpcsFile The file being scanned.
     * @param int $start The position of the ternary's first token.
     * @param int $end The position of its last token.
     * @param int $question The position of its `?`.
     * @param int $colon The position of its `:`.
     * @return ?ExpressionNode The ternary, or null if a part can't be read.
     */
    private static function parseTernary(
        File $phpcsFile,
        int $start,
        int $end,
        int $question,
        int $colon,
    ): ?ExpressionNode {
        $tokens = $phpcsFile->getTokens();
        $conditionEnd = $phpcsFile->findPrevious(T_WHITESPACE, ($question - 1), $start, true);
        $elseStart = $phpcsFile->findNext(T_WHITESPACE, ($colon + 1), ($end + 1), true);
        $isShort = $phpcsFile->findNext(T_WHITESPACE, ($question + 1), $colon, true) === false;
        $thenStart = $isShort ? false : $phpcsFile->findNext(T_WHITESPACE, ($question + 1), $colon, true);
        $thenEnd = $isShort ? false : $phpcsFile->findPrevious(T_WHITESPACE, ($colon - 1), $question, true);
        if (
            $conditionEnd === false || $elseStart === false
            || (!$isShort && ($thenStart === false || $thenEnd === false))
        ) {
            return null;
        }

        $parts = [[$start, $conditionEnd], ...($isShort ? [] : [[$thenStart, $thenEnd]]), [$elseStart, $end]];
        $children = [];
        foreach ($parts as [$partStart, $partEnd]) {
            $child = self::parse($phpcsFile, (int) $partStart, (int) $partEnd);
            if ($child === null) {
                return null;
            }

            $children[] = $child;
        }

        // On one line: the parts, with ` ? ` and ` : ` between them, or ` ?: ` for a short ternary.
        $flatLength = array_sum(array_map(static fn(ExpressionNode $child): int => $child->flatLength, $children))
            + ($isShort ? 4 : 6);

        return new ExpressionNode(ExpressionNode::TERNARY, $start, $end, $flatLength, $children, [$question, $colon]);
    }

    /**
     * Find the `=>` of a `match` arm with several conditions, when the expression is that whole arm.
     *
     * @param File $phpcsFile The file being scanned.
     * @param int $start The position of the expression's first token.
     * @param int $end The position of its last token.
     * @return ?int The position of the `=>`, or null if there isn't one outside any brackets, or the arm has one
     * condition, whose `=>` is read as an operator.
     */
    private static function findArmArrow(File $phpcsFile, int $start, int $end): ?int
    {
        $tokens = $phpcsFile->getTokens();
        for ($i = $start; $i <= $end; $i++) {
            if ($tokens[$i]['code'] === T_FN) {
                return null;
            }

            if ($tokens[$i]['code'] === T_MATCH_ARROW) {
                return ExpressionHelper::isArrowOperator($phpcsFile, $i) ? null : $i;
            }

            $i = ExpressionHelper::findNestedEnd($tokens[$i], $i);
        }

        return null;
    }

    /**
     * Parse a `match` arm with several conditions into a pair, whose key is the list of conditions.
     *
     * @param File $phpcsFile The file being scanned.
     * @param int $start The position of the arm's first token.
     * @param int $end The position of its last token.
     * @param int $arrow The position of its `=>`.
     * @return ?ExpressionNode The pair, or null if a part can't be read.
     */
    private static function parseArm(File $phpcsFile, int $start, int $end, int $arrow): ?ExpressionNode
    {
        $tokens = $phpcsFile->getTokens();
        $conditionsEnd = $phpcsFile->findPrevious(T_WHITESPACE, ($arrow - 1), $start, true);
        $valueStart = $phpcsFile->findNext(T_WHITESPACE, ($arrow + 1), ($end + 1), true);
        if ($conditionsEnd === false || $valueStart === false) {
            return null;
        }

        // The commas between the conditions, outside any brackets.
        $commas = [];
        for ($i = $start; $i <= $conditionsEnd; $i++) {
            if ($tokens[$i]['code'] === T_COMMA) {
                $commas[] = $i;
            }

            $i = ExpressionHelper::findNestedEnd($tokens[$i], $i);
        }

        $trailingComma = $commas !== [] && end($commas) === $conditionsEnd ? array_pop($commas) : null;
        $children = [];
        $conditionStart = $start;
        foreach ([...$commas, ($trailingComma ?? $conditionsEnd + 1)] as $boundary) {
            $last = $phpcsFile->findPrevious(T_WHITESPACE, ($boundary - 1), $conditionStart, true);
            $child = $last === false ? null : self::parse($phpcsFile, $conditionStart, $last);
            if ($child === null) {
                return null;
            }

            $children[] = $child;
            $conditionStart = (int) $phpcsFile->findNext(T_WHITESPACE, ($boundary + 1), null, true);
        }

        $value = self::parse($phpcsFile, $valueStart, $end);
        if ($value === null || count($children) < 2) {
            return null;
        }

        $listLength = array_sum(array_map(static fn(ExpressionNode $child): int => $child->flatLength, $children))
            + 2 * (count($children) - 1);
        $list = new ExpressionNode(
            kind: ExpressionNode::LIST,
            start: $start,
            end: $trailingComma ?? $children[count($children) - 1]->end,
            flatLength: $listLength,
            children: $children,
            separators: $commas,
            trailingComma: $trailingComma,
        );

        return new ExpressionNode(
            ExpressionNode::LEVEL,
            $start,
            $end,
            $listLength + 4 + $value->flatLength,
            [$list, $value],
            [$arrow],
        );
    }

    /**
     * Parse a run of operands and operators into a level of its loosest operators, whose operands are levels of the
     * tighter ones.
     *
     * @param File $phpcsFile The file being scanned.
     * @param int $start The position of the first token.
     * @param int $end The position of the last token.
     * @param non-empty-list<int> $operators The positions of the operators, outside any brackets.
     * @return ?ExpressionNode The level, or null if an operand has a line break inside it.
     */
    private static function parseLevel(File $phpcsFile, int $start, int $end, array $operators): ?ExpressionNode
    {
        $tokens = $phpcsFile->getTokens();
        $precedences = ExpressionHelper::precedences();
        $loosest
            = min(array_map(static fn(int $operator): int => $precedences[$tokens[$operator]['code']], $operators));

        $children = [];
        $levelOperators = [];
        $flatLength = 0;
        $segmentStart = $start;
        $segmentOperators = [];
        foreach ([...$operators, null] as $operator) {
            if ($operator !== null && $precedences[$tokens[$operator]['code']] !== $loosest) {
                $segmentOperators[] = $operator;
                continue;
            }

            $segmentEnd = $end;
            if ($operator !== null) {
                $segmentEnd = $phpcsFile->findPrevious(T_WHITESPACE, ($operator - 1), null, true);
            }

            if ($segmentEnd === false) {
                return null;
            }

            // An operand can be a ternary, as a pair's value can, which only parse() reads.
            $child = $segmentOperators === []
                ? self::parse($phpcsFile, $segmentStart, $segmentEnd)
                : self::parseLevel($phpcsFile, $segmentStart, $segmentEnd, $segmentOperators);
            if ($child === null) {
                return null;
            }

            $children[] = $child;
            $flatLength += $child->flatLength;
            if ($operator !== null) {
                $next = $phpcsFile->findNext(T_WHITESPACE, ($operator + 1), null, true);
                if ($next === false) {
                    return null;
                }

                $levelOperators[] = $operator;
                $flatLength += mb_strlen($tokens[$operator]['content']) + 2;
                $segmentStart = $next;
                $segmentOperators = [];
            }
        }

        return new ExpressionNode(ExpressionNode::LEVEL, $start, $end, $flatLength, $children, $levelOperators);
    }

    /**
     * Parse an operand: grouping parentheses, with any prefix operators before them, or anything else, which is kept as
     * it's written.
     *
     * @param File $phpcsFile The file being scanned.
     * @param int $start The position of the first token.
     * @param int $end The position of the last token.
     * @return ?ExpressionNode The operand or group, or null if it has a line break inside it.
     */
    private static function parseOperand(File $phpcsFile, int $start, int $end): ?ExpressionNode
    {
        $tokens = $phpcsFile->getTokens();
        $opener = $start;
        while (
            $opener < $end
            && ($tokens[$opener]['code'] === T_WHITESPACE || in_array($tokens[$opener]['code'], self::PREFIXES, true)
                || isset(Tokens::CAST_TOKENS[$tokens[$opener]['code']]))
        ) {
            $opener++;
        }

        $prefix = $phpcsFile->getTokensAsString($start, ($opener - $start));
        $isGroup = ExpressionHelper::isGrouping($phpcsFile, $opener)
            && ($tokens[$opener]['parenthesis_closer'] ?? null) === $end;
        if (!$isGroup) {
            $chain = self::parseChain($phpcsFile, $start, $end);
            if ($chain !== false) {
                return $chain;
            }

            // A call, an array or a body can span lines; any other operand that does is left alone.
            $call = self::parseCall($phpcsFile, $start, $end) ?? self::parseBody($phpcsFile, $start, $end);
            $text = $phpcsFile->getTokensAsString($start, ($end - $start + 1));
            if ($call !== null || str_contains($text, "\n")) {
                return $call;
            }

            return new ExpressionNode(ExpressionNode::OPERAND, $start, $end, mb_strlen($text));
        }

        $innerStart = $phpcsFile->findNext(T_WHITESPACE, ($opener + 1), $end, true);
        $innerEnd = $phpcsFile->findPrevious(T_WHITESPACE, ($end - 1), $opener, true);
        if (str_contains($prefix, "\n") || $innerStart === false || $innerEnd === false) {
            return null;
        }

        $inner = self::parse($phpcsFile, $innerStart, $innerEnd);
        if ($inner === null) {
            return null;
        }

        $flatLength = mb_strlen($prefix) + $inner->flatLength + 2;

        return new ExpressionNode(ExpressionNode::GROUP, $start, $end, $flatLength, [$inner], [], $opener, $end);
    }

    /**
     * Parse an operand that's a closure, `match` or anonymous class, whose body isn't laid out here.
     *
     * @param File $phpcsFile The file being scanned.
     * @param int $start The position of the operand's first token.
     * @param int $end The position of its last token.
     * @return ?ExpressionNode The body, or null if the operand isn't one, or its first line spans lines.
     */
    private static function parseBody(File $phpcsFile, int $start, int $end): ?ExpressionNode
    {
        $tokens = $phpcsFile->getTokens();
        $keyword = $tokens[$start]['code'] === T_STATIC
            ? $phpcsFile->findNext(Tokens::EMPTY_TOKENS, ($start + 1), $end, true)
            : $start;
        if ($keyword !== false && $tokens[$keyword]['code'] === T_NEW) {
            $keyword = $phpcsFile->findNext(Tokens::EMPTY_TOKENS, ($keyword + 1), $end, true);
            $keyword = $keyword !== false && $tokens[$keyword]['code'] === T_ANON_CLASS ? $keyword : false;
        }

        $opener = $keyword === false ? null : ($tokens[$keyword]['scope_opener'] ?? null);
        if (
            $keyword === false || !in_array($tokens[$keyword]['code'], [T_CLOSURE, T_MATCH, T_ANON_CLASS], true)
            || ($tokens[$keyword]['scope_closer'] ?? null) !== $end || !is_int($opener)
        ) {
            return null;
        }

        $head = $phpcsFile->getTokensAsString($start, ($opener - $start + 1));
        if (str_contains($head, "\n")) {
            return null;
        }

        return new ExpressionNode(
            kind: ExpressionNode::BODY,
            start: $start,
            end: $end,
            flatLength: ExpressionNode::BODY_LENGTH,
            headLength: mb_strlen($head),
            isClosure: $tokens[$keyword]['code'] === T_CLOSURE,
        );
    }

    /**
     * Parse an operand that ends with a call's argument list or an array's values, with at least one, into a call.
     *
     * @param File $phpcsFile The file being scanned.
     * @param int $start The position of the operand's first token.
     * @param int $end The position of its last token.
     * @return ?ExpressionNode The call, or null if the operand isn't one, or an argument can't be read.
     */
    private static function parseCall(File $phpcsFile, int $start, int $end): ?ExpressionNode
    {
        $tokens = $phpcsFile->getTokens();
        $opener = TokenHelper::findOpener($tokens[$end]);
        if ($opener === null || $opener < $start || !ExpressionHelper::isCallOpener($phpcsFile, $opener)) {
            return null;
        }

        // What comes before the bracket, as in `new Foo` or a chain's link, is joined onto one line, with a space only
        // between two words. A line break inside brackets in it, as in `foo(` then `)->bar()`, isn't read.
        $callee = '';
        $calleeGaps = [];
        for ($i = $start; $i < $opener; $i++) {
            $closer = TokenHelper::findCloser($tokens[$i]);
            if ($closer !== null && $closer > $i) {
                $inside = $phpcsFile->getTokensAsString($i, ($closer - $i + 1));
                if (str_contains($inside, "\n")) {
                    return null;
                }

                $callee .= $inside;
                $i = $closer;
                continue;
            }

            if ($tokens[$i]['code'] !== T_WHITESPACE) {
                $callee .= $tokens[$i]['content'];
                continue;
            }

            $runEnd = (int) $phpcsFile->findNext(T_WHITESPACE, ($i + 1), $opener, true) - 1;
            $run = $phpcsFile->getTokensAsString($i, ($runEnd - $i + 1));
            if (!str_contains($run, "\n")) {
                $callee .= $run;
                $i = $runEnd;
                continue;
            }

            $isBetweenWords = preg_match('/[\w$\\\\]$/', $tokens[$i - 1]['content']) === 1
                && preg_match('/^[\w$\\\\]/', $tokens[$runEnd + 1]['content']) === 1;
            $content = $isBetweenWords ? ' ' : '';
            for ($gap = $i; $gap <= $runEnd; $gap++) {
                $calleeGaps[$gap] = $gap === $i ? $content : '';
            }

            $callee .= $content;
            $i = $runEnd;
        }

        // The commas between the arguments, outside any brackets or bodies in them.
        $commas = [];
        for ($i = ($opener + 1); $i < $end; $i++) {
            if ($tokens[$i]['code'] === T_COMMA) {
                $commas[] = $i;
            }

            $i = ExpressionHelper::findNestedEnd($tokens[$i], $i);
        }

        $children = [];
        $separators = [];
        $trailingComma = null;
        $argumentStart = $opener;
        foreach ([...$commas, $end] as $boundary) {
            $first = $phpcsFile->findNext(T_WHITESPACE, ($argumentStart + 1), $boundary, true);
            // A destructuring list's skipped element, as in `[$a, , $c] = $list`, isn't read.
            if ($first === false && $boundary !== $end) {
                return null;
            }

            if ($first === false) {
                // Nothing after the last comma: a trailing comma.
                if ($commas !== []) {
                    $trailingComma = array_pop($separators);
                }

                break;
            }

            $last = (int) $phpcsFile->findPrevious(T_WHITESPACE, ($boundary - 1), $first, true);
            $child = self::parse($phpcsFile, $first, $last);
            if ($child === null) {
                return null;
            }

            $children[] = $child;
            if ($boundary !== $end) {
                $separators[] = $boundary;
            }

            $argumentStart = $boundary;
        }

        // On one line: what comes before the bracket, the bracket, the arguments with ", " between, and the closer. A
        // call with no arguments may still be split over lines, with nothing but whitespace between its brackets.
        $flatLength = mb_strlen($callee) + 2
            + array_sum(array_map(static fn(ExpressionNode $child): int => $child->flatLength, $children))
            + 2 * max(0, count($children) - 1);
        // isCallOpener() only takes an owned parenthesis if it's array()'s.
        $isArray = $tokens[$opener]['code'] === T_OPEN_SHORT_ARRAY || isset($tokens[$opener]['parenthesis_owner']);

        return new ExpressionNode(
            ExpressionNode::CALL,
            $start,
            $end,
            $flatLength,
            $children,
            [],
            $opener,
            $end,
            $separators,
            $trailingComma,
            $isArray,
            calleeGaps: $calleeGaps,
        );
    }
}
