<?php

declare(strict_types=1);

namespace Jumbo\Sniffs\PHP;

use Jumbo\Helpers\FunctionCallHelper;
use PHP_CodeSniffer\Files\File;
use PHP_CodeSniffer\Sniffs\Sniff;
use PHP_CodeSniffer\Util\Tokens;

/**
 * Reports calls to PHP's functions that have a better form, and fixes them where the fix keeps what the code does.
 *
 * PER Coding Style 3.1 has no rule for which functions to call, so this is an extra rule. Each check has its own code:
 * - CastFunction: `strval()`, `intval()`, `floatval()` and `boolval()` to a cast. A call with a base, or a first-class
 *   callable, is left alone.
 * - SetType: `settype($a, 'int');` to `$a = (int) $a;`, as a statement with a literal type.
 * - FunctionAlias: a function's alias to its main name, such as `sizeof()` to `count()`.
 * - DedicatedIniFunction: `ini_set()` for a setting with a function of its own, such as `set_time_limit()` for
 *   `max_execution_time`. Fixed only as a statement with a literal value, as the return values and types differ.
 * - ModernStringFunction: `strpos()`, `substr()`, `strncmp()` and `substr_compare()` comparisons to `str_contains()`,
 *   `str_starts_with()` and `str_ends_with()`. ArrayFirstLast: `$a[array_key_first($a)]` to `array_first($a)`, from
 *   PHP 8.5, going by PHP_CodeSniffer's `php_version`, which must be set. StringLength: `strlen($a) === 0` to
 *   `$a === ''`. Only patterns whose fix is certain to keep the behaviour are reported.
 * - ArrayPush: `array_push($a, $b);` with one value, as a statement, to `$a[] = $b;`. ImplodeSeparator:
 *   `implode($pieces)` to `implode('', $pieces)`. UselessSprintf and UselessPrintf: `sprintf('text')` to `'text'`, and
 *   `printf('text');` to `echo 'text';`, for a literal with no `%`. Pow: `pow($a, $b)` to `$a ** $b`. CallUserFunc:
 *   `call_user_func()` and `call_user_func_array()` to a direct call.
 * - FopenTextMode: a warning for `t` in a literal `fopen()` mode, Windows-only text mode. Not fixed.
 *
 * Only calls to PHP's global functions are checked: an unqualified name that a `use function` statement imports from
 * elsewhere, or that the file declares a function by, is left alone. Language constructs, such as `die` and `print`,
 * are Jumbo.PHP.LanguageConstructs'.
 */
class FunctionCallsSniff implements Sniff
{
    public const string CODE_CAST_FUNCTION = 'CastFunction';

    public const string CODE_SET_TYPE = 'SetType';

    public const string CODE_FUNCTION_ALIAS = 'FunctionAlias';

    public const string CODE_DEDICATED_INI_FUNCTION = 'DedicatedIniFunction';

    public const string CODE_MODERN_STRING_FUNCTION = 'ModernStringFunction';

    public const string CODE_ARRAY_FIRST_LAST = 'ArrayFirstLast';

    public const string CODE_STRING_LENGTH = 'StringLength';

    public const string CODE_ARRAY_PUSH = 'ArrayPush';

    public const string CODE_IMPLODE_SEPARATOR = 'ImplodeSeparator';

    public const string CODE_USELESS_SPRINTF = 'UselessSprintf';

    public const string CODE_USELESS_PRINTF = 'UselessPrintf';

    public const string CODE_POW = 'Pow';

    public const string CODE_CALL_USER_FUNC = 'CallUserFunc';

    public const string CODE_FOPEN_TEXT_MODE = 'FopenTextMode';

    /**
     * The functions that become casts.
     *
     * @var array<string, string>
     */
    private const array CASTS = ['strval' => 'string', 'intval' => 'int', 'floatval' => 'float', 'boolval' => 'bool'];

    /**
     * The types `settype()` takes, with their casts, or null for `null`.
     *
     * @var array<string, ?string>
     */
    private const array SET_TYPES = [
        'int' => 'int',
        'integer' => 'int',
        'float' => 'float',
        'double' => 'float',
        'string' => 'string',
        'bool' => 'bool',
        'boolean' => 'bool',
        'array' => 'array',
        'object' => 'object',
        'null' => null,
    ];

    /**
     * PHP's function aliases, from https://www.php.net/manual/en/aliases.php, with their main functions.
     *
     * @var array<string, string>
     */
    private const array ALIASES = [
        'chop' => 'rtrim',
        'diskfreespace' => 'disk_free_space',
        'dns_check_record' => 'checkdnsrr',
        'dns_get_mx' => 'getmxrr',
        'doubleval' => 'floatval',
        'fputs' => 'fwrite',
        'ftp_quit' => 'ftp_close',
        'gzputs' => 'gzwrite',
        'ini_alter' => 'ini_set',
        'is_double' => 'is_float',
        'is_integer' => 'is_int',
        'is_long' => 'is_int',
        'is_writeable' => 'is_writable',
        'join' => 'implode',
        'key_exists' => 'array_key_exists',
        'ldap_close' => 'ldap_unbind',
        'mysqli_escape_string' => 'mysqli_real_escape_string',
        'mysqli_set_opt' => 'mysqli_options',
        'openssl_get_privatekey' => 'openssl_pkey_get_private',
        'openssl_get_publickey' => 'openssl_pkey_get_public',
        'pcntl_errno' => 'pcntl_get_last_error',
        'pos' => 'current',
        'posix_errno' => 'posix_get_last_error',
        'session_commit' => 'session_write_close',
        'set_file_buffer' => 'stream_set_write_buffer',
        'show_source' => 'highlight_file',
        'sizeof' => 'count',
        'socket_get_status' => 'stream_get_meta_data',
        'socket_getopt' => 'socket_get_option',
        'socket_set_blocking' => 'stream_set_blocking',
        'socket_set_timeout' => 'stream_set_timeout',
        'socket_setopt' => 'socket_set_option',
        'stream_register_wrapper' => 'stream_wrapper_register',
        'strchr' => 'strstr',
        'user_error' => 'trigger_error',
    ];

    /**
     * The settings with a function of their own, with the function and the type of its argument, or null if it isn't
     * fixed.
     *
     * @var array<string, array{string, ?string}>
     */
    private const array INI_FUNCTIONS = [
        'max_execution_time' => ['set_time_limit', 'int'],
        'error_reporting' => ['error_reporting', 'int'],
        'date.timezone' => ['date_default_timezone_set', 'string'],
        'include_path' => ['set_include_path', 'string'],
        'bcmath.scale' => ['bcscale', 'int'],
        'ignore_user_abort' => ['ignore_user_abort', 'bool'],
        'mbstring.language' => ['mb_language', 'string'],
        'mbstring.substitute_character' => ['mb_substitute_character', 'int|string'],
        'mbstring.regex_encoding' => ['mb_regex_encoding', 'string'],
        'session.name' => ['session_name', 'string'],
        'session.save_path' => ['session_save_path', 'string'],
        'session.cache_limiter' => ['session_cache_limiter', 'string'],
    ];

    /**
     * The tokens that can come before a comparison that's a whole operand, as `===` binds more tightly than they do.
     *
     * @var list<int|string>
     */
    private const array LOOSER_BEFORE = [
        T_OPEN_PARENTHESIS,
        T_OPEN_SQUARE_BRACKET,
        T_OPEN_SHORT_ARRAY,
        T_COMMA,
        T_SEMICOLON,
        T_OPEN_CURLY_BRACKET,
        T_CLOSE_CURLY_BRACKET,
        T_OPEN_TAG,
        T_OPEN_TAG_WITH_ECHO,
        T_RETURN,
        T_ECHO,
        T_PRINT,
        T_YIELD,
        T_BOOLEAN_AND,
        T_BOOLEAN_OR,
        T_LOGICAL_AND,
        T_LOGICAL_OR,
        T_LOGICAL_XOR,
        T_COALESCE,
        T_INLINE_THEN,
        T_INLINE_ELSE,
        T_DOUBLE_ARROW,
        T_MATCH_ARROW,
        T_FN_ARROW,
    ];

    /**
     * The tokens that can come after a comparison that's a whole operand.
     *
     * @var list<int|string>
     */
    private const array LOOSER_AFTER = [
        T_CLOSE_PARENTHESIS,
        T_CLOSE_SQUARE_BRACKET,
        T_CLOSE_SHORT_ARRAY,
        T_COMMA,
        T_SEMICOLON,
        T_CLOSE_TAG,
        T_BOOLEAN_AND,
        T_BOOLEAN_OR,
        T_LOGICAL_AND,
        T_LOGICAL_OR,
        T_LOGICAL_XOR,
        T_COALESCE,
        T_INLINE_THEN,
        T_INLINE_ELSE,
        T_DOUBLE_ARROW,
        T_MATCH_ARROW,
    ];

    /**
     * Returns an array of tokens this test wants to listen for.
     *
     * @return array<int|string>
     */
    public function register(): array
    {
        return [T_STRING, T_NAME_FULLY_QUALIFIED];
    }

    /**
     * Processes this test, when one of its tokens is encountered.
     *
     * @param File $phpcsFile The file being scanned.
     * @param int $stackPtr The position of the current token in the stack.
     */
    public function process(File $phpcsFile, int $stackPtr): void
    {
        $name = FunctionCallHelper::globalFunctionName($phpcsFile, $stackPtr);
        $arguments = $name === null ? null : FunctionCallHelper::arguments($phpcsFile, $stackPtr);
        if ($name === null || $arguments === null) {
            return;
        }

        match (true) {
            isset(self::CASTS[$name]) => self::processCast($phpcsFile, $stackPtr, $name, $arguments),
            $name === 'settype' => self::processSetType($phpcsFile, $stackPtr, $arguments),
            in_array($name, ['ini_set', 'ini_alter'], true)
                => self::processIniSet($phpcsFile, $stackPtr, $name, $arguments),
            isset(self::ALIASES[$name]) => self::processAlias($phpcsFile, $stackPtr, $name),
            $name === 'strpos' => self::processStrpos($phpcsFile, $stackPtr, $arguments),
            $name === 'substr', $name === 'strncmp', $name === 'substr_compare'
                => self::processStartsOrEnds($phpcsFile, $stackPtr, $name, $arguments),
            $name === 'array_key_first', $name === 'array_key_last'
                => $this->processArrayKey($phpcsFile, $stackPtr, $name, $arguments),
            $name === 'strlen' => $this->processStrlen($phpcsFile, $stackPtr, $arguments),
            $name === 'array_push' => self::processArrayPush($phpcsFile, $stackPtr, $arguments),
            $name === 'implode' => self::processImplode($phpcsFile, $stackPtr, $arguments),
            $name === 'sprintf', $name === 'printf' => self::processSprintf($phpcsFile, $stackPtr, $name, $arguments),
            $name === 'pow' => $this->processPow($phpcsFile, $stackPtr, $arguments),
            $name === 'call_user_func', $name === 'call_user_func_array'
                => self::processCallUserFunc($phpcsFile, $stackPtr, $name, $arguments),
            $name === 'fopen' => self::processFopen($phpcsFile, $stackPtr, $arguments),
            default => null,
        };
    }

    /**
     * Report `strval()`, `intval()`, `floatval()` or `boolval()`, and fix it to a cast.
     *
     * @param File $phpcsFile The file being scanned.
     * @param int $stackPtr The position of the function's name.
     * @param string $name The function, in lower case.
     * @param list<array{start: int, end: int, clean: string}> $arguments The arguments.
     */
    private static function processCast(File $phpcsFile, int $stackPtr, string $name, array $arguments): void
    {
        if (count($arguments) !== 1) {
            return;
        }

        $cast = self::CASTS[$name];
        $error = 'Use a (%s) cast, not %s()';
        if ($phpcsFile->addFixableError($error, $stackPtr, self::CODE_CAST_FUNCTION, [$cast, $name])) {
            $operand = self::operand($phpcsFile, $arguments[0]);
            self::replaceCall($phpcsFile, $stackPtr, "({$cast}) {$operand}");
        }
    }

    /**
     * Report `settype()` as a statement with a literal type, and fix it to a cast.
     *
     * @param File $phpcsFile The file being scanned.
     * @param int $stackPtr The position of the function's name.
     * @param list<array{start: int, end: int, clean: string}> $arguments The arguments.
     */
    private static function processSetType(File $phpcsFile, int $stackPtr, array $arguments): void
    {
        $type = count($arguments) === 2
            ? FunctionCallHelper::stringValue($phpcsFile, $arguments[1]['start'], $arguments[1]['end'])
            : null;
        $type = $type === null ? null : strtolower($type);
        $variable = $arguments[0]['clean'] ?? '';
        if (
            $type === null || !array_key_exists($type, self::SET_TYPES) || preg_match('/^\$\w+$/', $variable) !== 1
            || !FunctionCallHelper::isStatement(
                $phpcsFile,
                $stackPtr,
                FunctionCallHelper::closer($phpcsFile, $stackPtr),
            )
        ) {
            return;
        }

        $cast = self::SET_TYPES[$type];
        $code = $cast === null ? "{$variable} = null" : "{$variable} = ({$cast}) {$variable}";
        if ($phpcsFile->addFixableError('Use %s, not settype()', $stackPtr, self::CODE_SET_TYPE, [$code])) {
            self::replaceCall($phpcsFile, $stackPtr, $code);
        }
    }

    /**
     * Report a function's alias, and fix it to the main name.
     *
     * @param File $phpcsFile The file being scanned.
     * @param int $stackPtr The position of the function's name.
     * @param string $name The alias, in lower case.
     */
    private static function processAlias(File $phpcsFile, int $stackPtr, string $name): void
    {
        $content = $phpcsFile->getTokens()[$stackPtr]['content'];
        $main = (str_starts_with($content, '\\') ? '\\' : '') . self::ALIASES[$name];
        $error = 'Use %s(), not its alias %s()';
        if ($phpcsFile->addFixableError($error, $stackPtr, self::CODE_FUNCTION_ALIAS, [$main, $content])) {
            $phpcsFile->fixer->replaceToken($stackPtr, $main);
        }
    }

    /**
     * Report `ini_set()` for a setting with a function of its own, or `ini_alter()` as an alias.
     *
     * @param File $phpcsFile The file being scanned.
     * @param int $stackPtr The position of the function's name.
     * @param string $name `ini_set` or `ini_alter`.
     * @param list<array{start: int, end: int, clean: string}> $arguments The arguments.
     */
    private static function processIniSet(File $phpcsFile, int $stackPtr, string $name, array $arguments): void
    {
        $setting = count($arguments) === 2
            ? FunctionCallHelper::stringValue($phpcsFile, $arguments[0]['start'], $arguments[0]['end'])
            : null;
        $function = $setting === null ? null : (self::INI_FUNCTIONS[$setting] ?? null);
        if ($function === null && $setting !== null && str_starts_with($setting, 'session.cookie_')) {
            $function = ['session_set_cookie_params', null];
        }

        if ($function === null) {
            if ($name === 'ini_alter') {
                self::processAlias($phpcsFile, $stackPtr, $name);
            }

            return;
        }

        [$replacement, $type] = $function;
        $value = $type === null ? null : self::convertIniValue($phpcsFile, $arguments[1], $type);
        $isFixable = $value !== null && FunctionCallHelper::isStatement(
            $phpcsFile,
            $stackPtr,
            FunctionCallHelper::closer($phpcsFile, $stackPtr),
        );
        $error = 'Use %s() instead of %s(\'%s\')';
        $data = [$replacement, $name, (string) $setting];
        if (!$isFixable) {
            $phpcsFile->addError($error, $stackPtr, self::CODE_DEDICATED_INI_FUNCTION, $data);
            return;
        }

        if ($phpcsFile->addFixableError($error, $stackPtr, self::CODE_DEDICATED_INI_FUNCTION, $data)) {
            $prefix = str_starts_with($phpcsFile->getTokens()[$stackPtr]['content'], '\\') ? '\\' : '';
            self::replaceCall($phpcsFile, $stackPtr, "{$prefix}{$replacement}({$value})");
        }
    }

    /**
     * Convert an `ini_set()` value to the type a setting's function takes, if it's a literal.
     *
     * @param File $phpcsFile The file being scanned.
     * @param array{start: int, end: int, clean: string} $argument The value.
     * @param string $type `int`, `string`, `bool` or `int|string`.
     * @return ?string The value's code, or null if it isn't a literal that converts.
     */
    private static function convertIniValue(File $phpcsFile, array $argument, string $type): ?string
    {
        $tokens = $phpcsFile->getTokens();
        $code = $tokens[$argument['start']]['code'];
        if ($argument['start'] !== $argument['end']) {
            return null;
        }

        $string = FunctionCallHelper::stringValue($phpcsFile, $argument['start'], $argument['end']);
        $literal = $tokens[$argument['start']]['content'];
        $isInt = $code === T_LNUMBER || ($string !== null && preg_match('/^-?\d+$/', $string) === 1);

        return match ($type) {
            'int' => $isInt ? (string) (int) ($string ?? $literal) : null,
            'string' => $string !== null ? $literal : null,
            'int|string' => $isInt ? (string) (int) ($string ?? $literal) : ($string !== null ? $literal : null),
            'bool' => match (true) {
                $code === T_TRUE, $code === T_FALSE => strtolower($literal),
                $string !== null && in_array(strtolower($string), ['1', 'on', 'true', 'yes'], true) => 'true',
                $string !== null && in_array(strtolower($string), ['0', 'off', 'false', 'no', ''], true) => 'false',
                $code === T_LNUMBER && in_array($literal, ['0', '1'], true) => $literal === '1' ? 'true' : 'false',
                default => null,
            },
            default => null,
        };
    }

    /**
     * Report `strpos($h, $n) === false` and the like, and fix them to `str_contains()` or `str_starts_with()`.
     *
     * @param File $phpcsFile The file being scanned.
     * @param int $stackPtr The position of the function's name.
     * @param list<array{start: int, end: int, clean: string}> $arguments The arguments.
     */
    private static function processStrpos(File $phpcsFile, int $stackPtr, array $arguments): void
    {
        $comparison = count($arguments) === 2 ? self::findComparison($phpcsFile, $stackPtr) : null;
        if ($comparison === null) {
            return;
        }

        [$start, $end, $operator, $other] = $comparison;
        $value = strtolower($other);
        $function = match ($value) {
            'false' => 'str_contains',
            '0' => 'str_starts_with',
            default => null,
        };
        if ($function === null || !in_array($operator, ['===', '!=='], true)) {
            return;
        }

        $isPositive = ($operator === '!==') === ($value === 'false');
        self::reportModern($phpcsFile, $stackPtr, $start, $end, $function, $arguments, $isPositive);
    }

    /**
     * Report a `substr()`, `strncmp()` or `substr_compare()` comparison that's a `str_starts_with()` or
     * `str_ends_with()`, and fix it.
     *
     * @param File $phpcsFile The file being scanned.
     * @param int $stackPtr The position of the function's name.
     * @param string $name The function, in lower case.
     * @param list<array{start: int, end: int, clean: string}> $arguments The arguments.
     */
    private static function processStartsOrEnds(File $phpcsFile, int $stackPtr, string $name, array $arguments): void
    {
        $comparison = self::findComparison($phpcsFile, $stackPtr);
        if ($comparison === null || !in_array($comparison[2], ['===', '!=='], true)) {
            return;
        }

        [$start, $end, $operator, $other] = $comparison;
        $count = count($arguments);
        $normalized = self::normalize($other);
        $needle = null;
        $function = null;
        if ($name === 'substr' && $count === 3 && $arguments[1]['clean'] === '0') {
            $length = self::strlenArgument($phpcsFile, $arguments[2], false);
            $needle = $length === $normalized ? $other : null;
            $function = 'str_starts_with';
        } elseif ($name === 'substr' && $count === 2) {
            $length = self::strlenArgument($phpcsFile, $arguments[1], true);
            $needle = $length === $normalized ? $other : null;
            $function = 'str_ends_with';
        } elseif ($name === 'strncmp' && $count === 3 && $other === '0') {
            $length = self::strlenArgument($phpcsFile, $arguments[2], false);
            $needle = $length === self::normalize($arguments[1]['clean']) ? $arguments[1]['clean'] : null;
            $function = 'str_starts_with';
        } elseif ($name === 'substr_compare' && $count === 3 && $other === '0') {
            $length = self::strlenArgument($phpcsFile, $arguments[2], true);
            $needle = $length === self::normalize($arguments[1]['clean']) ? $arguments[1]['clean'] : null;
            $function = 'str_ends_with';
        }

        // For an empty needle, the old code's -0 takes the whole string, so only a non-empty literal is certain.
        if (
            $needle === null || $function === null
            || ($function === 'str_ends_with' && !self::isNonEmptyLiteral($needle))
        ) {
            return;
        }

        $haystack = $arguments[0];
        $newArguments = [$haystack, ['start' => 0, 'end' => 0, 'clean' => $needle]];
        self::reportModern($phpcsFile, $stackPtr, $start, $end, $function, $newArguments, $operator === '===');
    }

    /**
     * Report `$a[array_key_first($a)]` or `$a[array_key_last($a)]`, and fix it to `array_first($a)` or
     * `array_last($a)`, from PHP 8.5.
     *
     * @param File $phpcsFile The file being scanned.
     * @param int $stackPtr The position of the function's name.
     * @param string $name The function, in lower case.
     * @param list<array{start: int, end: int, clean: string}> $arguments The arguments.
     */
    private function processArrayKey(File $phpcsFile, int $stackPtr, string $name, array $arguments): void
    {
        $tokens = $phpcsFile->getTokens();
        $bracket = $phpcsFile->findPrevious(Tokens::EMPTY_TOKENS, ($stackPtr - 1), null, true);
        $closer = FunctionCallHelper::closer($phpcsFile, $stackPtr);
        $after = $phpcsFile->findNext(Tokens::EMPTY_TOKENS, ($closer + 1), null, true);
        if (
            (FunctionCallHelper::minimumPhpVersion() ?? 0) < 80500 || count($arguments) !== 1 || $bracket === false
            || $after === false || $tokens[$bracket]['code'] !== T_OPEN_SQUARE_BRACKET
            || ($tokens[$bracket]['bracket_closer'] ?? null) !== $after
        ) {
            return;
        }

        $array = self::normalize($arguments[0]['clean']);
        $start = $this->findArrayStart($phpcsFile, $bracket, $array);
        if ($start === null) {
            return;
        }

        $function = $name === 'array_key_first' ? 'array_first' : 'array_last';
        $error = 'Use %s(), not the array accessed at %s()';
        if ($phpcsFile->addFixableError($error, $stackPtr, self::CODE_ARRAY_FIRST_LAST, [$function, $name])) {
            self::replace($phpcsFile, $start, $after, "{$function}({$arguments[0]['clean']})");
        }
    }

    /**
     * Report `strlen($a) === 0`, `!== 0` or `> 0`, and fix it to a comparison with `''`.
     *
     * @param File $phpcsFile The file being scanned.
     * @param int $stackPtr The position of the function's name.
     * @param list<array{start: int, end: int, clean: string}> $arguments The arguments.
     */
    private function processStrlen(File $phpcsFile, int $stackPtr, array $arguments): void
    {
        $comparison = count($arguments) === 1 ? self::findComparison($phpcsFile, $stackPtr) : null;
        if ($comparison === null || $comparison[3] !== '0') {
            return;
        }

        [$start, $end, $operator, , $isReversed] = $comparison;
        $operator = $isReversed ? strtr($operator, ['<' => '>', '>' => '<']) : $operator;
        $expected = match ($operator) {
            '===' => '===',
            '!==', '>' => '!==',
            default => null,
        };
        if ($expected === null) {
            return;
        }

        $code = "{$this->operand($phpcsFile, $arguments[0])} {$expected} ''";
        if ($phpcsFile->addFixableError('Use %s, not strlen()', $stackPtr, self::CODE_STRING_LENGTH, [$code])) {
            self::replace($phpcsFile, $start, $end, $code);
        }
    }

    /**
     * Report `array_push($a, $b);` with one value, and fix it to `$a[] = $b;`.
     *
     * @param File $phpcsFile The file being scanned.
     * @param int $stackPtr The position of the function's name.
     * @param list<array{start: int, end: int, clean: string}> $arguments The arguments.
     */
    private static function processArrayPush(File $phpcsFile, int $stackPtr, array $arguments): void
    {
        if (
            count($arguments) !== 2
            || !FunctionCallHelper::isSimpleOperand($phpcsFile, $arguments[0]['start'], $arguments[0]['end'])
            || !FunctionCallHelper::isStatement(
                $phpcsFile,
                $stackPtr,
                FunctionCallHelper::closer($phpcsFile, $stackPtr),
            )
        ) {
            return;
        }

        $code = "{$arguments[0]['clean']}[] = {$arguments[1]['clean']}";
        if ($phpcsFile->addFixableError('Use %s, not array_push()', $stackPtr, self::CODE_ARRAY_PUSH, [$code])) {
            self::replaceCall($phpcsFile, $stackPtr, $code);
        }
    }

    /**
     * Report `implode()` without a separator, and fix it by adding `''`.
     *
     * @param File $phpcsFile The file being scanned.
     * @param int $stackPtr The position of the function's name.
     * @param list<array{start: int, end: int, clean: string}> $arguments The arguments.
     */
    private static function processImplode(File $phpcsFile, int $stackPtr, array $arguments): void
    {
        if (count($arguments) !== 1) {
            return;
        }

        $error = 'implode() must be given a separator; use \'\' for none';
        if ($phpcsFile->addFixableError($error, $stackPtr, self::CODE_IMPLODE_SEPARATOR)) {
            $phpcsFile->fixer->addContentBefore($arguments[0]['start'], "'', ");
        }
    }

    /**
     * Report `sprintf('text')` or `printf('text');` with a literal that has no `%`, and fix it to the literal, or an
     * `echo` of it.
     *
     * @param File $phpcsFile The file being scanned.
     * @param int $stackPtr The position of the function's name.
     * @param string $name `sprintf` or `printf`.
     * @param list<array{start: int, end: int, clean: string}> $arguments The arguments.
     */
    private static function processSprintf(File $phpcsFile, int $stackPtr, string $name, array $arguments): void
    {
        $tokens = $phpcsFile->getTokens();
        $format = $arguments[0] ?? null;
        if (
            count($arguments) !== 1 || $format === null || $format['start'] !== $format['end']
            || $tokens[$format['start']]['code'] !== T_CONSTANT_ENCAPSED_STRING
            || str_contains($tokens[$format['start']]['content'], '%')
        ) {
            return;
        }

        if ($name === 'sprintf') {
            $error = 'sprintf() isn\'t needed for a string with no placeholders';
            if ($phpcsFile->addFixableError($error, $stackPtr, self::CODE_USELESS_SPRINTF)) {
                self::replaceCall($phpcsFile, $stackPtr, $format['clean']);
            }

            return;
        }

        if (
            !FunctionCallHelper::isStatement($phpcsFile, $stackPtr, FunctionCallHelper::closer($phpcsFile, $stackPtr))
        ) {
            return;
        }

        $error = 'Use echo, not printf(), for a string with no placeholders';
        if ($phpcsFile->addFixableError($error, $stackPtr, self::CODE_USELESS_PRINTF)) {
            self::replaceCall($phpcsFile, $stackPtr, "echo {$format['clean']}");
        }
    }

    /**
     * Report `pow($a, $b)`, and fix it to `$a ** $b`.
     *
     * @param File $phpcsFile The file being scanned.
     * @param int $stackPtr The position of the function's name.
     * @param list<array{start: int, end: int, clean: string}> $arguments The arguments.
     */
    private function processPow(File $phpcsFile, int $stackPtr, array $arguments): void
    {
        if (count($arguments) !== 2) {
            return;
        }

        $code = "{$this->operand($phpcsFile, $arguments[0])} ** {$this->operand($phpcsFile, $arguments[1])}";
        $tokens = $phpcsFile->getTokens();
        $next = $phpcsFile->findNext(
            Tokens::EMPTY_TOKENS,
            (FunctionCallHelper::closer($phpcsFile, $stackPtr) + 1),
            null,
            true,
        );
        if ($next !== false && $tokens[$next]['code'] === T_POW) {
            // ** is right-associative, so pow($a, $b) ** $c needs the parentheses.
            $code = "({$code})";
        }

        if ($phpcsFile->addFixableError('Use the ** operator, not pow()', $stackPtr, self::CODE_POW)) {
            self::replaceCall($phpcsFile, $stackPtr, $code);
        }
    }

    /**
     * Report `call_user_func()` or `call_user_func_array()`, and fix it to a direct call.
     *
     * @param File $phpcsFile The file being scanned.
     * @param int $stackPtr The position of the function's name.
     * @param string $name The function, in lower case.
     * @param list<array{start: int, end: int, clean: string}> $arguments The arguments.
     */
    private static function processCallUserFunc(File $phpcsFile, int $stackPtr, string $name, array $arguments): void
    {
        $callable = $arguments[0] ?? null;
        $rest = array_slice($arguments, 1);
        if ($callable === null || ($name === 'call_user_func_array' && count($rest) !== 1)) {
            return;
        }

        $target = self::directCallTarget($phpcsFile, $callable);
        foreach ($rest as $argument) {
            // A call that changes the callable's variable as it's made would call something else.
            if ($target !== null && preg_match('/\$\w+\s*=(?!=)/', $argument['clean']) === 1) {
                $target = null;
            }
        }

        if ($target === null) {
            return;
        }

        $list = $name === 'call_user_func_array'
            ? "...{$rest[0]['clean']}"
            : implode(', ', array_map(static fn(array $argument): string => $argument['clean'], $rest));
        $error = 'Call the function directly, not through %s()';
        if ($phpcsFile->addFixableError($error, $stackPtr, self::CODE_CALL_USER_FUNC, [$name])) {
            self::replaceCall($phpcsFile, $stackPtr, "{$target}({$list})");
        }
    }

    /**
     * Work out how to call a callable directly: a function's name, `$object->method` or a variable.
     *
     * @param File $phpcsFile The file being scanned.
     * @param array{start: int, end: int, clean: string} $callable The callable argument.
     * @return ?string The code before the arguments, or null if it can't be called directly for certain.
     */
    private static function directCallTarget(File $phpcsFile, array $callable): ?string
    {
        $tokens = $phpcsFile->getTokens();
        $start = $callable['start'];
        $end = $callable['end'];
        if ($start === $end && $tokens[$start]['code'] === T_VARIABLE) {
            return $tokens[$start]['content'];
        }

        $function = FunctionCallHelper::stringValue($phpcsFile, $start, $end);
        if ($function !== null && preg_match('/^\\\\?[A-Za-z_][A-Za-z0-9_]*$/', $function) === 1) {
            $isNamespaced = $phpcsFile->findPrevious(T_NAMESPACE, $start) !== false;

            return ($isNamespaced && !str_starts_with($function, '\\') ? '\\' : '') . $function;
        }

        if ($tokens[$start]['code'] !== T_OPEN_SHORT_ARRAY || ($tokens[$start]['bracket_closer'] ?? null) !== $end) {
            return null;
        }

        $pattern = '/^\[\s*(\$\w+)\s*,\s*([\'"])([A-Za-z_][A-Za-z0-9_]*)\2\s*\]$/';
        if (preg_match($pattern, $callable['clean'], $match) !== 1) {
            return null;
        }

        return "{$match[1]}->{$match[3]}";
    }

    /**
     * Report `t` in a literal `fopen()` mode, Windows-only text mode.
     *
     * @param File $phpcsFile The file being scanned.
     * @param int $stackPtr The position of the function's name.
     * @param list<array{start: int, end: int, clean: string}> $arguments The arguments.
     */
    private static function processFopen(File $phpcsFile, int $stackPtr, array $arguments): void
    {
        $mode = isset($arguments[1])
            ? FunctionCallHelper::stringValue($phpcsFile, $arguments[1]['start'], $arguments[1]['end'])
            : null;
        if ($mode !== null && str_contains($mode, 't')) {
            $warning = 'The fopen() mode "t" is Windows-only text mode, which translates line endings there';
            $phpcsFile->addWarning($warning, $arguments[1]['start'], self::CODE_FOPEN_TEXT_MODE);
        }
    }

    /**
     * Report a comparison that's a modern string function, and fix it.
     *
     * @param File $phpcsFile The file being scanned.
     * @param int $stackPtr The position of the old function's name.
     * @param int $start The position of the comparison's first token.
     * @param int $end The position of its last token.
     * @param string $function The new function.
     * @param array<array{start: int, end: int, clean: string}> $arguments The new function's arguments.
     * @param bool $isPositive Whether the comparison is true when the new function is.
     */
    private static function reportModern(
        File $phpcsFile,
        int $stackPtr,
        int $start,
        int $end,
        string $function,
        array $arguments,
        bool $isPositive,
    ): void {
        $prefix = str_starts_with($phpcsFile->getTokens()[$stackPtr]['content'], '\\') ? '\\' : '';
        $list = implode(', ', array_map(static fn(array $argument): string => $argument['clean'], $arguments));
        $code = ($isPositive ? '' : '!') . "{$prefix}{$function}({$list})";
        $error = 'Use %s, which says what\'s meant';
        if ($phpcsFile->addFixableError($error, $stackPtr, self::CODE_MODERN_STRING_FUNCTION, [$code])) {
            self::replace($phpcsFile, $start, $end, $code);
        }
    }

    /**
     * Find the comparison a call is one side of, when the comparison is a whole operand, so it can be replaced.
     *
     * @param File $phpcsFile The file being scanned.
     * @param int $stackPtr The position of the function's name.
     * @return array{int, int, string, string, bool}|null The comparison's first and last tokens, its operator, the
     *     other side's code, and whether the call is on the right, or null if there's no such comparison.
     */
    private static function findComparison(File $phpcsFile, int $stackPtr): ?array
    {
        $tokens = $phpcsFile->getTokens();
        $comparisons = [T_IS_IDENTICAL, T_IS_NOT_IDENTICAL, T_GREATER_THAN, T_LESS_THAN];
        $closer = FunctionCallHelper::closer($phpcsFile, $stackPtr);
        $after = $phpcsFile->findNext(Tokens::EMPTY_TOKENS, ($closer + 1), null, true);
        $before = $phpcsFile->findPrevious(Tokens::EMPTY_TOKENS, ($stackPtr - 1), null, true);
        if ($after !== false && in_array($tokens[$after]['code'], $comparisons, true)) {
            $other = self::operandAfter($phpcsFile, $after);
            $edge = $before;
            $result = $other === null ? null : [$stackPtr, $other[1], $tokens[$after]['content'], $other[2], false];
        } elseif ($before !== false && in_array($tokens[$before]['code'], $comparisons, true)) {
            $other = self::operandBefore($phpcsFile, $before);
            $edge = $other === null ? false : self::previousCode($phpcsFile, $other[0]);
            $result = $other === null ? null : [$other[0], $closer, $tokens[$before]['content'], $other[2], true];
        } else {
            return null;
        }

        if ($result === null) {
            return null;
        }

        $next = $phpcsFile->findNext(Tokens::EMPTY_TOKENS, ($result[1] + 1), null, true);
        $isLooserBefore = $edge === false || in_array($tokens[$edge]['code'], self::LOOSER_BEFORE, true)
            || isset(Tokens::ASSIGNMENT_TOKENS[$tokens[$edge]['code']]);
        $isBounded = $isLooserBefore && $next !== false && in_array($tokens[$next]['code'], self::LOOSER_AFTER, true);

        return $isBounded ? $result : null;
    }

    /**
     * Find the operand after a comparison operator, up to anything that binds more loosely.
     *
     * @param File $phpcsFile The file being scanned.
     * @param int $operator The position of the operator.
     * @return array{int, int, string}|null Its first and last tokens and its code, or null if there's none.
     */
    private static function operandAfter(File $phpcsFile, int $operator): ?array
    {
        $tokens = $phpcsFile->getTokens();
        $start = $phpcsFile->findNext(Tokens::EMPTY_TOKENS, ($operator + 1), null, true);
        if ($start === false) {
            return null;
        }

        $end = $start;
        for ($i = $start; isset($tokens[$i]); $i++) {
            $code = $tokens[$i]['code'];
            if (in_array($code, self::LOOSER_AFTER, true) || isset(Tokens::COMPARISON_TOKENS[$code])) {
                break;
            }

            if ($code !== T_WHITESPACE) {
                $end = $tokens[$i]['parenthesis_closer'] ?? $tokens[$i]['bracket_closer'] ?? $i;
                $i = $end;
            }
        }

        return [$start, $end, trim($phpcsFile->getTokensAsString($start, ($end - $start + 1)))];
    }

    /**
     * Find the operand before a comparison operator, back to anything that binds more loosely.
     *
     * @param File $phpcsFile The file being scanned.
     * @param int $operator The position of the operator.
     * @return array{int, int, string}|null Its first and last tokens and its code, or null if there's none.
     */
    private static function operandBefore(File $phpcsFile, int $operator): ?array
    {
        $tokens = $phpcsFile->getTokens();
        $end = $phpcsFile->findPrevious(Tokens::EMPTY_TOKENS, ($operator - 1), null, true);
        if ($end === false) {
            return null;
        }

        $start = $end;
        for ($i = $end; $i >= 0; $i--) {
            $code = $tokens[$i]['code'];
            if (
                in_array($code, self::LOOSER_BEFORE, true) || isset(Tokens::ASSIGNMENT_TOKENS[$code])
                || isset(Tokens::COMPARISON_TOKENS[$code])
            ) {
                break;
            }

            if ($code !== T_WHITESPACE) {
                $start = $tokens[$i]['parenthesis_opener'] ?? $tokens[$i]['bracket_opener'] ?? $i;
                $i = $start;
            }
        }

        return [$start, $end, trim($phpcsFile->getTokensAsString($start, ($end - $start + 1)))];
    }

    /**
     * Get the argument of a `strlen()` call that's a whole argument, optionally negated, as in `-strlen($n)`.
     *
     * @param File $phpcsFile The file being scanned.
     * @param array{start: int, end: int, clean: string} $argument The argument.
     * @param bool $negated Whether it must be negated.
     * @return ?string The `strlen()` argument's code, normalized, or null if the argument isn't such a call.
     */
    private static function strlenArgument(File $phpcsFile, array $argument, bool $negated): ?string
    {
        $tokens = $phpcsFile->getTokens();
        $start = $argument['start'];
        if ($negated) {
            if ($tokens[$start]['code'] !== T_MINUS) {
                return null;
            }

            $start = (int) $phpcsFile->findNext(Tokens::EMPTY_TOKENS, ($start + 1), null, true);
        }

        if (FunctionCallHelper::globalFunctionName($phpcsFile, $start) !== 'strlen') {
            return null;
        }

        $inner = FunctionCallHelper::arguments($phpcsFile, $start);
        if (
            $inner === null || count($inner) !== 1
            || FunctionCallHelper::closer($phpcsFile, $start) !== $argument['end']
        ) {
            return null;
        }

        return self::normalize($inner[0]['clean']);
    }

    /**
     * Find the start of the array before an array access's `[`, if it's the given code, and nothing comes before it
     * that's part of the same expression.
     *
     * @param File $phpcsFile The file being scanned.
     * @param int $bracket The position of the `[`.
     * @param string $array The array's code, normalized.
     * @return ?int The position of the array's first token, or null if it isn't the given array.
     */
    private function findArrayStart(File $phpcsFile, int $bracket, string $array): ?int
    {
        $tokens = $phpcsFile->getTokens();
        $text = '';
        for ($i = ($bracket - 1); $i >= 0; $i--) {
            if ($tokens[$i]['code'] === T_WHITESPACE) {
                continue;
            }

            $opener = $tokens[$i]['bracket_opener'] ?? $tokens[$i]['parenthesis_opener'] ?? null;
            $from = is_int($opener) && $opener < $i ? $opener : $i;
            $text = "{$this->normalize($phpcsFile->getTokensAsString($from, ($i - $from + 1)))}{$text}";
            $i = $from;
            if ($text === $array) {
                $previous = $phpcsFile->findPrevious(Tokens::EMPTY_TOKENS, ($from - 1), null, true);
                $chain = [T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR, T_DOUBLE_COLON, T_DOLLAR, T_NS_SEPARATOR];

                return $previous !== false && in_array($tokens[$previous]['code'], $chain, true) ? null : $from;
            }

            if (strlen($text) >= strlen($array)) {
                return null;
            }
        }

        return null;
    }

    /**
     * Check whether code is a string literal that isn't empty.
     *
     * @param string $code The code.
     * @return bool True if it is.
     */
    private static function isNonEmptyLiteral(string $code): bool
    {
        return preg_match('/^(\'(?:[^\'\\\\]|\\\\.)+\'|"[^"$\\\\]+")$/s', $code) === 1;
    }

    /**
     * Get an argument's code as an operand beside an operator or after a cast, in parentheses unless it's simple.
     *
     * @param File $phpcsFile The file being scanned.
     * @param array{start: int, end: int, clean: string} $argument The argument.
     * @return string The code.
     */
    private static function operand(File $phpcsFile, array $argument): string
    {
        return FunctionCallHelper::isSimpleOperand($phpcsFile, $argument['start'], $argument['end'])
            ? $argument['clean']
            : "({$argument['clean']})";
    }

    /**
     * Normalize code for comparing two pieces of it: without white space outside its string literals.
     *
     * @param string $code The code.
     * @return string The code without that white space.
     */
    private static function normalize(string $code): string
    {
        $pattern = '/(\'(?:[^\'\\\\]|\\\\.)*\'|"(?:[^"\\\\]|\\\\.)*")|\s+/s';

        return preg_replace_callback($pattern, static fn(array $match): string => $match[1] ?? '', $code) ?? $code;
    }

    /**
     * Find the last code before a token.
     *
     * @param File $phpcsFile The file being scanned.
     * @param int $stackPtr The position of the token.
     * @return false|int The position of the code, or false if there's none.
     */
    private static function previousCode(File $phpcsFile, int $stackPtr): false|int
    {
        return $phpcsFile->findPrevious(Tokens::EMPTY_TOKENS, ($stackPtr - 1), null, true);
    }

    /**
     * Replace a call, from its name to its closing parenthesis.
     *
     * @param File $phpcsFile The file being fixed.
     * @param int $stackPtr The position of the function's name.
     * @param string $code The new code.
     */
    private static function replaceCall(File $phpcsFile, int $stackPtr, string $code): void
    {
        self::replace($phpcsFile, $stackPtr, FunctionCallHelper::closer($phpcsFile, $stackPtr), $code);
    }

    /**
     * Replace a range of tokens with new code.
     *
     * @param File $phpcsFile The file being fixed.
     * @param int $start The position of the first token.
     * @param int $end The position of the last token.
     * @param string $code The new code.
     */
    private static function replace(File $phpcsFile, int $start, int $end, string $code): void
    {
        $phpcsFile->fixer->beginChangeset();
        $phpcsFile->fixer->replaceToken($start, $code);
        for ($i = ($start + 1); $i <= $end; $i++) {
            $phpcsFile->fixer->replaceToken($i, '');
        }

        $phpcsFile->fixer->endChangeset();
    }
}
