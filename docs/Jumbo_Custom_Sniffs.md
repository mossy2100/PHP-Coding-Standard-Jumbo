# Jumbo Custom Sniffs

The sniffs written for the [Jumbo](Jumbo.md) standard. Each one fills a gap in the [inherited sniffs](Jumbo_Inherited_Sniffs.md), or replaces an inherited sniff
that misreads modern PHP. Each section gives the reason. The custom sniffs for PER Coding Style itself are in
[PERCS31 Custom Sniffs](PERCS31_Custom_Sniffs.md).

| Sniff                                                      | Checks                                                          | Fixable |
| ---------------------------------------------------------- | --------------------------------------------------------------- | ------- |
| `Jumbo.Arrays.ArrayDeclaration`                            | A multi-line array is on one line if it fits                    | Yes     |
| `Jumbo.Arrays.ElementComma`                                | A multi-line array element's comma on the element's last line   | Yes     |
| `Jumbo.Classes.ClassMembers`                               | Members as the header allows, as `private` in a final class     | Mostly  |
| `Jumbo.Classes.ConstantSpacing`                            | Blank lines after a constant, safe on the last line             | Yes     |
| `Jumbo.Classes.EmptyLinesAroundClassBraces`                | No blank lines inside class braces, allowing `{}`               | Yes     |
| `Jumbo.Classes.EnumCaseSpacing`                            | Blank lines after an enum case, safe on the last line           | Yes     |
| `Jumbo.Classes.PropertySpacing`                            | Blank lines after a property, safe on the last line             | Yes     |
| `Jumbo.Classes.RequireSingleLineClassHeader`               | A split class, interface or enum header fits on a line          | Yes     |
| `Jumbo.Classes.RequireSingleLineMethodSignature`           | A short signature on one line, skipping a promoted hook         | Yes     |
| `Jumbo.Commenting.BlockComment`                            | A block comment's indents, and the spaces around one on a line  | Yes     |
| `Jumbo.Commenting.DisallowHashComments`                    | `//` for a comment, not `#`, except `#region` and `#endregion`  | Yes     |
| `Jumbo.Commenting.PhpDocTags`                              | Docblock tag names in their usual case, with no aliases         | Yes     |
| `Jumbo.Commenting.TrailingComment`                         | One space between code and a `//` comment after it              | Yes     |
| `Jumbo.ControlStructures.HeaderLayout`                     | A header on one line if it fits, else split with its condition  | Yes     |
| `Jumbo.ControlStructures.LanguageConstructWithParentheses` | No `echo('x')`, allowing `exit()` and `die()`                   | Yes     |
| `Jumbo.ControlStructures.MatchArmWrapIndent`               | One line if it fits, else a wrapped `match` arm indented once   | Yes     |
| `Jumbo.ControlStructures.RequireShortTernaryOperator`      | `$a ?: $b`, skipping a nullable type in a `set` hook            | Yes     |
| `Jumbo.Formatting.RequireSingleLineStatement`              | A split statement or `case` label fits on a line                | Yes     |
| `Jumbo.Functions.RequireSingleLineCall`                    | A call that fits is on one line, skipping a class or `match`    | Yes     |
| `Jumbo.Functions.RequireSingleLineSignature`               | A split function or closure signature fits on a line            | Yes     |
| `Jumbo.Namespaces.UnusedUses`                              | No unused `use` statements, safe with a miscased `use Function` | Yes     |
| `Jumbo.NamingConventions.ValidNamespaceName`               | A namespace's name in ASCII                                     | No      |
| `Jumbo.NamingConventions.ValidVariableName`                | No leading underscore on a variable or parameter in a class     | No      |
| `Jumbo.Operators.IncrementStyle`                           | `$i++;`, not `++$i;`, where the value isn't used                | Yes     |
| `Jumbo.PHP.BuiltInNameCase`                                | Built-in classes and magic methods in their own case            | Yes     |
| `Jumbo.PHP.DisallowInvisibleCharacters`                    | No invisible characters, such as a tab or zero-width space      | Mostly  |
| `Jumbo.PHP.EmbeddedPhp`                                    | A block's tags on their own lines, level with its code          | Mostly  |
| `Jumbo.PHP.FunctionCalls`                                  | Calls with a better form, such as a cast for `intval()`         | Mostly  |
| `Jumbo.PHP.IntegerLiteral`                                 | `0xFF` and `0o755`, not `0XFF`, `0xff` or `0755`                | Yes     |
| `Jumbo.PHP.SimplifySyntax`                                 | The simpler form, such as `while ($a)` for `for (; $a;)`        | Mostly  |
| `Jumbo.PhpUnit.RequireDedicatedAssertions`                 | PHPUnit's dedicated assertions, such as `assertIsInt($a)`       | Yes     |
| `Jumbo.PhpUnit.TestClass`                                  | No PHPUnit annotations, and the `setUp()` hooks `protected`     | Yes     |
| `Jumbo.Strings.RequireBracedVariables`                     | Braces around every variable in a string, as in `"{$name}"`     | Yes     |
| `Jumbo.TypeHints.DeclareStrictTypes`                       | `strict_types=1` required, allowing `<?php declare(...) ?>`     | Yes     |
| `Jumbo.TypeHints.ParameterTypeHint`                        | Parameter type hints, skipping a promoted hook                  | Yes     |
| `Jumbo.TypeHints.ParameterTypeHintSpacing`                 | Parameter type hint spacing, skipping a promoted hook           | Yes     |
| `Jumbo.WhiteSpace.BlockBlankLines`                         | No blank line at either end of a block `declare` or a hook      | Yes     |
| `Jumbo.WhiteSpace.DocBlockSpacing`                         | No blank line after a doc comment, before any declaration       | Yes     |
| `Jumbo.WhiteSpace.LanguageConstructSpacing`                | One space after `return`, `echo` and others, with no line break | Yes     |

## Jumbo.Arrays.ArrayDeclaration

Requires a multi-line array to be written on one line if it fits.

**Why:** no inherited sniff collapses a multi-line array that fits on one line. The stock array sniffs (see [Inherited
Sniffs](Jumbo_Inherited_Sniffs.md)) only check the layout of an array that's already multi-line.

**Auto-fixable:** yes.

- **Fits** means the whole resulting line is within `maxLineLength`: the text before the opening bracket, the collapsed
  array, and the text after the closing bracket on its line. A fix never creates a line that's too long.
- **Outermost first.** If an enclosing array fits, only that one is reported, as fixing it collapses the nested ones.
- **Clean output.** The fix uses single spaces, no space inside brackets or around `->`, `?->` and `::`, and no trailing
  commas.
- **Skipped** if the array contains a comment, a heredoc or nowdoc, a multi-line string, or a brace body such as a
  closure, anonymous class or `match` expression.

**Good:**

```php
$user = ['name' => 'John', 'email' => 'john@example.com'];

$words = [
    'The quick brown fox jumps over the lazy dog.',
    'Pack my box with five dozen liquor jugs, please.',
    'How vexingly quick daft zebras jump!',
];
```

**Bad:**

```php
$user = [
    'name' => 'John',
    'email' => 'john@example.com',
];
```

**Configuration:**

| Property        | Type | Default | Description                                |
| --------------- | ---- | ------- | ------------------------------------------ |
| `maxLineLength` | int  | 120     | Maximum line length for a collapsed array. |

To use a different limit, set every line-length property, as described in [Line Length](LineLength.md).

---

## Jumbo.Arrays.ElementComma

Requires the comma after an element of a multi-line array or destructuring list to be on the element's last line, not
the next one.

**Why:** PER Coding Style says nothing about where an array element's comma goes. Squiz `ArrayDeclaration`'s `NoComma`
check covers it, but only for a keyed element, and it expects the comma on the line after a heredoc's closing marker, as
before PHP 7.3. So it reports `EOT,`, and its fix puts the comma on a line of its own. PERCS31 excludes it, and this
sniff replaces it in Jumbo, for keyed and unkeyed elements, in arrays and in destructuring lists such as
`[$a, $b] = $pair;`. A heredoc's or nowdoc's closing marker counts as the element's last line.

**Auto-fixable:** yes, unless a comment is between the element and its comma.

**Good:**

```php
$messages = [
    'greeting' => <<<EOT
        Hello, {$name}.
        EOT,
    'farewell' => 'Goodbye.',
];
```

**Bad:**

```php
$messages = [
    'greeting' => 'Hello.'
    , 'farewell' => 'Goodbye.',
];
```

---

## Jumbo.Classes.ClassMembers

Checks a class's members against what its header allows:

- **`ProtectedToPrivate`:** a `protected` constant, property, method or set visibility, as in `protected(set)`, in a
  final class that extends no class and uses no trait becomes `private`, as nothing else can see it.
- **`StaticPrivateMethod`:** a private method whose body never uses `$this` becomes `static`, and its `$this->name()`
  calls in the class become `self::name()`. It's left alone if it calls a method of the class that isn't static, or
  one the sniff can't see, or uses `parent::`, a closure that isn't static, or `get_class()` with no argument. A method
  that calls one made static qualifies on phpcbf's next pass. A magic method is never made static.
- **`MissingStringable`:** a class with `__toString()` that neither extends a class nor implements an interface gets
  `implements \Stringable`, which PHP adds anyway. With another interface, `Stringable` may already be inherited.
- **`SleepWakeup`,** a warning: `__sleep()` and `__wakeup()` are superseded by `__serialize()` and
  `__unserialize()`. Not fixed, as `__sleep()` returns property names where `__serialize()` returns the data.

**Why:** PER Coding Style 3.1 has no rules for these, so this is an extra rule. Each says what the code already does:
a member nothing else can reach is private, a method that needs no object is static, and a class that can be a string
says so. These are php-cs-fixer's `protected_to_private`, `static_private_method`, `stringable_for_to_string` and
`modern_serialization_methods`.

**Auto-fixable:** yes, except `SleepWakeup`.

**Good:**

```php
final class Price implements \Stringable
{
    private int $cents;

    public function __toString(): string
    {
        return self::format($this->cents);
    }

    private static function format(int $cents): string
    {
        return number_format($cents / 100, 2);
    }
}
```

**Bad:**

```php
final class Price
{
    protected int $cents;

    public function __toString(): string
    {
        return $this->format($this->cents);
    }

    private function format(int $cents): string
    {
        return number_format($cents / 100, 2);
    }
}
```

---

## Jumbo.Classes.ConstantSpacing, EnumCaseSpacing and PropertySpacing

Enforce the blank lines after a class constant, enum case or property. These replace
`SlevomatCodingStandard.Classes.ConstantSpacing`, `EnumCaseSpacing` and `PropertySpacing`, and skip a member on the last
line of a file.

**Why:** the parents assume there's a line after every member, so `public int $a = 1; }` on a file's last line is a PHP
fatal error. That ends the whole run, and loses the report for every other file. PERCS31 reports the closing brace
anyway. Each sniff only adds that one check, and can go once Slevomat fixes it.

**Auto-fixable:** yes, as the parents.

---

## Jumbo.Classes.EmptyLinesAroundClassBraces

Disallows blank lines after a class's opening brace and before its closing brace. This replaces
`SlevomatCodingStandard.Classes.EmptyLinesAroundClassBraces`.

**Why:** `SlevomatCodingStandard.Classes.EmptyLinesAroundClassBraces` rejects an empty body written as `{}` on one line,
which [PER Coding Style 3.1, Sections 4 and 4.4](https://www.php-fig.org/per/coding-style/#4-classes-properties-and-methods) recommends. This sniff skips that case, and leaves everything else to the
parent. See [PERCS31.Formatting.EmptyBody](PERCS31_Custom_Sniffs.md#percs31formattingemptybody).

**Auto-fixable:** yes, as the parent.

---

## Jumbo.Classes.RequireSingleLineMethodSignature

Requires a method signature that fits within the line length to be written on one line, skipping one with a hook on a
promoted constructor parameter. This replaces `SlevomatCodingStandard.Classes.RequireSingleLineMethodSignature`.

**Why:** the parent takes the `;` in a hook on a promoted constructor parameter, e.g.
`public string $name { set => trim($value); }`, as the end of the signature, so its fix joins only part of it, and
`phpcbf` can't finish. [PER Coding Style 3.1, Section 4.9](https://www.php-fig.org/per/coding-style/#49-property-hooks) allows a single short hook there, so this sniff skips a
function with one, and leaves everything else to the parent. See [`PERCS31.Classes.PropertyHooks`](PERCS31_Custom_Sniffs.md#percs31classespropertyhooks).

**Auto-fixable:** yes, as the parent.

---

## Jumbo.Commenting.BlockComment

Checks the layout of a block comment, `/* ... */`. A docblock, `/** ... */`, isn't checked.

**A comment over several lines:**

- Each line after the first is indented at least as far as the line the `/*` is on. A blank line is allowed.
- A `*/` on a line by itself lines up with the `/*`.
- Text may share a line with the `/*` or the `*/`.
- A comment with a `*` starting each line is allowed, and its `*/` may be one column to the right, under the stars.

Where the `/*` itself goes is checked by [`PERCS31.WhiteSpace.ScopeIndent`](PERCS31_Custom_Sniffs.md#percs31whitespacescopeindent), as for a statement.

**A comment on one line:**

- One space after `/*`, and one before `*/`, as in `/* Text */`.
- With code before it on the line, one space before it. With code after it, one space after it.
- The space between a comment and a bracket, comma or operator beside it, or a semicolon after it, isn't checked here,
  as the sniffs for those report it. So `foo(/* none */ $a)` has no space after the `(`.

**Why:** PER Coding Style 3.1 has no rule for comments, so this is an extra rule. `Squiz.Commenting.BlockComment` wants
the text indented four spaces past the `/*`, which isn't this layout.

**Auto-fixable:** yes.

**Good:**

```php
if ($ready) {
    /*
    Start the engine.
    Then wait.
    */
    $speed = /* metres per second */ $engine->start();
}
```

**Bad:**

```php
if ($ready) {
    /*
  Start the engine.
        */
    $speed =   /*metres per second*/   $engine->start();
}
```

---

## Jumbo.Commenting.DisallowHashComments

Requires `//` for a single-line comment, not `#`, except for the words in its `exceptions` property, `region` and
`endregion` by default. `# Text` is fixed to `// Text`, and `#Text` to `// Text`, adding the space
`Squiz.Commenting.InlineComment` requires. Only the marker changes, never the comment's text.

- An exception is a whole word straight after the `#`, then a space or the end of the line. Matching is
  case-sensitive, so `#region Constants` and `#endregion` are allowed, but `#regional`, `#Region` and `# region`
  aren't.
- An attribute (`#[...]`), a shebang line before `<?php`, and a `#` in a string or block comment aren't comments, so
  they aren't checked.
- Set `exceptions` to a comma-separated list of words, or to an empty string to report every `#` comment.

**Why:** PER Coding Style 3.1 has no rule for comments, so this is an extra rule. `//` is the usual marker, and one
marker is easier to read and search for. The vendor sniffs for this (Squiz and PEAR) report every `#` comment,
including `#region`, which editors fold code on.

**Auto-fixable:** yes.

**Good:**

```php
#region Constants

// Seconds in a day.
const DAY = 86400;

#endregion
```

**Bad:**

```php
# Seconds in a day.
const DAY = 86400;
```

---

## Jumbo.Commenting.PhpDocTags

Checks the tags in a docblock: their names, the format of inline tags, a function's `@param` and `@return` tags, and
every `@var` tag.

| Code                   | Reported when                                                                   |
| ---------------------- | ------------------------------------------------------------------------------- |
| `Casing`               | A known tag name is in the wrong case, such as `@Return` or `@inheritdoc`       |
| `Alias`                | A tag is an alias, in any case: see the table below                             |
| `InlineFormat`         | An inline tag isn't written `{@name description}`, such as `@{see Foo}`         |
| `UnknownParam`         | A `@param`'s name matches no parameter. Not fixed                               |
| `DuplicateParam`       | A `@param` repeats one before it. Not fixed                                     |
| `ParamTypeMismatch`    | A `@param`'s type doesn't match the parameter's native type. Not fixed          |
| `ReturnTypeMismatch`   | A `@return`'s type doesn't match the native return type. Not fixed              |
| `MissingType`          | A `@param` or `@return` has no type, as in `@param $x The value.` Not fixed     |
| `Spacing`              | A `@param` or `@return` hasn't exactly one space between its parts              |
| `SelfReference`        | A `@return` has `this` or `@this` for `$this`, or `$self` or `@self` for `self` |
| `VarTypeMismatch`      | A property's or constant's `@var` type doesn't match its native type. Not fixed |
| `VarName`              | A property's or constant's `@var` names the variable, which is redundant        |
| `VarNameOrder`         | An inline `@var` has the name before the type, as in `@var $a int`              |
| `VarInBlockComment`    | An inline `@var` is in a `/* */` comment, which tools ignore                    |
| `ParamOrder`           | The `@param` tags aren't in the order of the parameters. Not fixed              |
| `ParamsNotTogether`    | Another tag or a blank line is between `@param` tags. Not fixed                 |
| `ReturnNotAfterParams` | A `@return` comes before a `@param`. Not fixed                                  |
| `ThrowsNotAfterReturn` | A `@throws` comes before the `@return` or a `@param`. Not fixed                 |

| Alias          | Replaced by   |
| -------------- | ------------- |
| `@inheritDocs` | `@inheritDoc` |
| `@returns`     | `@return`     |
| `@type`        | `@var`        |
| `@const`       | `@var`        |
| `@link`        | `@see`        |

- **Casing:** the known names are the ones `SlevomatCodingStandard.Commenting.AnnotationName` knows: phpDocumentor's
  tags, PHPUnit's, and the static analysis tags, with their `phpstan-` and `psalm-` forms. So `@inheritDoc` is in camel
  case.
- **Inline format:** checked for `example`, `id`, `inheritDoc`, `internal`, `link`, `see`, `source`, `toc` and
  `tutorial`, the tags that can be inline. The `@` goes inside a single brace at each end, with no space after the
  opening brace or before the closing one.
- **Block and inline tags:** both are checked, so `{@inheritdoc}` is fixed to `{@inheritDoc}`, and `{@link url}` to
  `{@see url}`.
- **Left alone:**
  - a block tag that a `use` statement imports, such as a Doctrine or JMS annotation
  - a name followed by `(` or `\`, as in `@Type("string")` or `@ORM\Column`
  - an unknown tag, such as `@static`
  - `@property-read` and `@property-write`, which aren't really aliases: PHPStan reports a write to a `@property-read`
  - text that only looks like a tag, in the middle of a sentence
  - an inline tag split over two lines
- **`@param` and `@return`:** checked in the docblock of a function or method, each by its main name, so `@returns`
  is checked as `@return` before it's renamed.
  - A type matches if each of its members is the native type or a more specific form of it, going by
    [PHPStan's types](https://phpstan.org/writing-php-code/phpdoc-types): `list<int>` or `array<string, int>` for
    `array`, `positive-int` for `int`, `true` for `bool`, `int|null` for `?int`, `$this` for `static`. A class, a
    template type or a type alias can't be compared without loading code, so a type with one isn't reported, and nor
    is a variadic parameter's.
  - An unknown or repeated `@param` isn't fixed, as its description may be worth keeping, or the parameter may be
    what's missing.
  - **Spacing:** exactly one space between the tag, its type, the variable and the start of the description. The
    description isn't touched.
  - A tag isn't required, and one that only repeats the native type is removed by the `UselessAnnotation` codes of
    `Jumbo.TypeHints.ParameterTypeHint` and `SlevomatCodingStandard.TypeHints.ReturnTypeHint`.
- **`@var`:** on a property, a constant or an inline variable. A property's or typed constant's type is matched as
  above. `MissingType` and `Spacing` apply as above, and a one-line doc comment needs one space after its `/**` and
  before its `*/`: `/**@var int*/` becomes `/** @var int */`. The variable name in a property's or constant's `@var`
  is removed, as it's redundant and can drift; an inline `@var` keeps its name. An inline `@var` with its name first
  is reordered, and one in a `/* */` comment becomes a doc comment. In Jumbo, this replaces
  `PhpCollective.Commenting.InlineDocBlock`. An inline `@var` on an assignment becomes an `assert()`, by
  `SlevomatCodingStandard.PHP.RequireExplicitAssertion`, where it can be.

- **Where the tags are:** no tag has to be in any place, so `@see`, `@todo`, `@fixme` and the rest can go where they
  read best, including first. Only the `@param` tags must be together and in order, then `@return`, then `@throws`.
  These replace `Generic.Commenting.DocComment`'s `ParamNotFirst`, `ParamGroup` and `TagsNotGrouped`, which put the
  `@param` tags first and every tag with the others of its name.

**Why:** PER Coding Style 3.1 has no rule for docblocks, so this is an extra rule. It replaces
`SlevomatCodingStandard.Commenting.AnnotationName`, whose block tag check skips every tag in a file with a `namespace`
statement, so it hardly ever ran. The tag names are from that sniff, the aliases from PHP-CS-Fixer's
`phpdoc_no_alias_tag` and `general_phpdoc_tag_rename`, and the inline format check is adapted from its
`phpdoc_inline_tag_normalizer`. The spacing and self-reference checks are from its `phpdoc_single_line_var_spacing`
(widened to `@param` and `@return`) and `phpdoc_return_self_reference`. A type that doesn't match is a bug in the
documentation, and these replace `PhpCollective.Commenting.DocBlockParamNotJustNull`'s `EmptyType`.

**Auto-fixable:** partly: the names, inline tags, spacing and self-references.

**Good:**

```php
/**
 * {@inheritDoc}
 *
 * @param positive-int $count The number of items.
 * @return int The total.
 * @see Basket::total()
 */
public function total(int $count): int
```

**Bad:**

```php
/**
 * { @inheritdoc }
 *
 * @Param string  $count The number of items.
 * @returns int The total.
 * @link Basket::total()
 */
public function total(int $count): int
```

---

## Jumbo.Commenting.TrailingComment

Requires a single space between code and a `//` or `#` comment after it on the same line. So comments after code aren't
lined up in a column.

A comment on a line of its own isn't checked here, nor is a block comment, or a comment after a PHP open tag.

**Why:** PER Coding Style 3.1 has no rule for comments, so this is an extra rule. No inherited sniff checks it: the
Squiz and Slevomat sniffs for a comment after code disallow it altogether.

**Auto-fixable:** yes.

**Good:**

```php
$speed = 0; // Not moving.
```

**Bad:**

```php
$speed = 0;// Not moving.
$count = 10;    // Lined up.
```

---

## Jumbo.ControlStructures.HeaderLayout

Requires a control structure's header on one line if it fits, and split over lines if it doesn't. It covers `if`,
`elseif`, `while`, including a `do`-`while`'s, `foreach`, `for`, `switch` and `match`.

**Why:** PER allows either layout for a header that fits on one line, so joining one is an extra rule, in Jumbo only.
For one that doesn't fit, PER shows the layout but no sniff produces it. A header split over lines is also often left
over after `Jumbo.Arrays.ArrayDeclaration` collapses the array inside it.

- **A header split over lines** is joined if the whole resulting line, up to and including ` {`, fits within
  `maxLineLength`. A header with a comment, heredoc, multi-line string or brace body is left alone, as for
  [Jumbo.Arrays.ArrayDeclaration](#jumboarraysarraydeclaration). Reported as `ShouldBeSingleLine`.
- **A header on one line that's too long** is split. Its condition goes on its own line after the `(`, indented once,
  and `) {` goes on the next line, level with the keyword's line. A `for` header's expressions go on a line each.
  Reported as `ShouldBeMultiLine`.

The condition itself isn't changed. If it's still too long on its own line,
[`ExpressionFormat`](PERCS31_Custom_Sniffs.md#percs31formattingexpressionformat) splits it, and `PERCS31.ControlStructures.ControlStructureSpacing`
puts a `foreach`'s `as` on its own line.

**Auto-fixable:** yes.

**Good:**

```php
foreach ([$m->inv(), $m->pow(-1)] as $inverse) {
    // ...
}

if (
    is_int($caseCloser) && $caseCloser < $endOfScope && in_array($tokens[$caseCloser]['code'], self::TERMINATORS, true)
) {
    return true;
}
```

**Bad:**

```php
foreach (
    [$m->inv(), $m->pow(-1)] as $inverse
) {
    // ...
}

if (is_int($caseCloser) && $caseCloser < $endOfScope && in_array($tokens[$caseCloser]['code'], self::TERMINATORS, true)) {
    return true;
}
```

**Configuration:**

| Property        | Type | Default | Description          |
| --------------- | ---- | ------- | -------------------- |
| `maxLineLength` | int  | 120     | Maximum line length. |

To use a different limit, set every line-length property, as described in [Line Length](LineLength.md).

---

## Jumbo.ControlStructures.LanguageConstructWithParentheses

Disallows parentheses around the argument of a language construct, such as `echo('x')`, except `exit` and `die`. This
replaces `SlevomatCodingStandard.ControlStructures.LanguageConstructWithParentheses`.

**Why:** the Slevomat sniff reports `die()` and fixes it to `die`. [PER Coding Style 3.1, Section 4.7](https://www.php-fig.org/per/coding-style/#47-method-and-function-calls) says `exit()` and
`die()` SHOULD have parentheses, which `Universal.PHP.RequireExitDieParentheses` checks, so the two sniffs would undo
each other's fixes. This sniff skips `exit` and `die`, and leaves everything else to the parent.

**Auto-fixable:** yes, as the parent.

---

## Jumbo.ControlStructures.MatchArmWrapIndent

Requires a `match` arm's conditions, its `=>` and the first line of its value to be on one line, if they fit. Otherwise,
it sets where the `=>` goes and how the arm's lines are indented. Set `indent` to change the number of spaces, which is
4 by default.

An arm that doesn't fit is wrapped in one of two ways:

- **The conditions stay on one line.** A line starting with the `=>`, or with the value after it, is indented one level
  past the arm's first line.
- **The conditions are split,** one per line with a trailing comma, as PERCS31 requires. Each is level with the first,
  and the `=>` starts the next line, indented once.

**Why:** PER Coding Style 3.1 doesn't say how a wrapped arm is laid out, so this is an extra rule, matching how Jumbo
treats calls and arrays that fit, and keeping each arm distinct. Putting the `=>` on its own line after split conditions
is what makes their trailing comma useful: adding a condition then changes one line. PERCS31 allows the `=>` on the
last condition's line too. `PERCS31.Operators.OperatorPlacement` checks a line starting with an operator. A comment
among the arm's parts is left to a person.

**Auto-fixable:** yes.

**Good:**

```php
$label = match ($code) {
    200, 201 => 'OK',
    $thisIsALongCondition, $andThisIsAnotherOne, $andAThirdCondition, $andAFourthCondition, $andAFifthCondition
        => $this->describe($code),
    $thisIsAnExtremelyLongConditionThatTakesUpMostOfTheLineByItself,
    $andThisIsAnotherOneThatWillNotFitOnTheSameLineAsThatOne,
        => 'Long',
    default => 'Error',
};
```

**Bad:**

```php
$label = match ($code) {
    200,
    201, => 'OK',
    $thisIsAnExtremelyLongConditionThatTakesUpMostOfTheLineByItself,
        $andThisIsAnotherOneThatWillNotFitOnTheSameLineAsThatOne, => 'Long',
    default => 'Error',
};
```

**Configuration:**

| Property        | Type | Default | Description                                  |
| --------------- | ---- | ------- | -------------------------------------------- |
| `indent`        | int  | 4       | The number of spaces in one indent level.    |
| `maxLineLength` | int  | 120     | Maximum line length for an arm's first line. |

---

## Jumbo.ControlStructures.RequireShortTernaryOperator

Requires `$a ?: $b` instead of `$a ? $a : $b`. This replaces
`SlevomatCodingStandard.ControlStructures.RequireShortTernaryOperator`.

**Why:** PHP_CodeSniffer reads the `?` in `set(?int $value)` as a ternary's, as it doesn't know about hooks. The
Slevomat sniff then looks for a `:` that isn't there, and the error stops the file being checked. This sniff skips that
`?`, and leaves everything else to the parent.

**Auto-fixable:** yes, as the parent.

---

## Jumbo.Formatting.RequireSingleLineStatement, and the signature and class header sniffs

Require code split across lines to be written on one line, if the whole resulting line fits within `maxLineLength`.

| Sniff                                         | Covers                                                 |
| --------------------------------------------- | ------------------------------------------------------ |
| `Jumbo.Classes.RequireSingleLineClassHeader`  | A class, interface or enum's `extends`, `implements`   |
| `Jumbo.Formatting.RequireSingleLineStatement` | A statement or `case` label, outside its calls, arrays |
| `Jumbo.Functions.RequireSingleLineSignature`  | A function or closure's signature, and a `use` list    |

**Why:** PER allows these splits, so this is an extra rule, in Jumbo only. Other sniffs already do the same for a call,
an array, a method signature, a condition and a loop header.

These are left alone:

- A chain of two or more methods, each on its own line. A single wrapped `->` is collapsed.
- A comment, a heredoc or nowdoc, or a multi-line string.
- A line break only inside a call or array, whose own sniffs report it.
- A line break before or after a binary operator, or just inside grouping parentheses, in an expression that
  [`ExpressionFormat`](PERCS31_Custom_Sniffs.md#percs31formattingexpressionformat) lays out.

A statement with a closure, `match` or anonymous class body, or a call or array too long for one line, can't be
collapsed. So the lines around it are joined instead, and it moves left to match:

```php
$a = $b
    ?? function () {
        return 1;
    };

// Becomes:
$a = $b ?? function () {
    return 1;
};
```

**Auto-fixable:** yes.

**Good:**

```php
$a = $b ? 1 : 2;

$query
    ->select()
    ->where();

/**
 * @param list<int> $items
 */
function total(array $items): int
{
    return 0;
}
```

**Bad:**

```php
$a
    = $b ? 1 : 2;

$a = $b
    ? 1
    : 2;

$a = $b ? (
    $c
) : 2;

/**
 * @param list<int> $items
 */
function total(
    array $items,
): int {
    return 0;
}
```

**Configuration:**

| Property        | Type | Default | Description                               |
| --------------- | ---- | ------- | ----------------------------------------- |
| `maxLineLength` | int  | 120     | Maximum line length for a collapsed line. |

To use a different limit, set every line-length property, as described in [Line Length](LineLength.md).

---

## Jumbo.Functions.RequireSingleLineCall

Requires a function, method or constructor call that fits within the line length to be written on one line. This
replaces `SlevomatCodingStandard.Functions.RequireSingleLineCall`, and skips a call with an anonymous class or `match`
argument.

**Why:** the parent's `ignoreWithComplexParameter` option skips a call with a closure, arrow function or array argument,
but not one with an anonymous class. So it reports `consume(new class {` ... `});`, and its fix puts the whole class
body on one line, which then crashes Slevomat's property spacing sniff. Its fix collapses a `match` too, which other
sniffs expand again, until `phpcbf` gives up.

**Auto-fixable:** yes, as the parent.

**Configuration:**

| Property                     | Type | Default | Description                                                                       |
| ---------------------------- | ---- | ------- | --------------------------------------------------------------------------------- |
| `maxLineLength`              | int  | 120     | The longest line a call may be joined onto.                                       |
| `ignoreWithComplexParameter` | bool | true    | Skip a call with a closure, arrow function, array, anonymous class or inner call. |

---

## Jumbo.Namespaces.UnusedUses

Disallows unused `use` statements, including in annotations. This replaces
`SlevomatCodingStandard.Namespaces.UnusedUses`.

**Why:** the Slevomat sniff reads the type of a `use` statement with a case-sensitive check, so
`use Function Vendor\helper;` is treated as a class import. It then reports the import as unused, and `phpcbf` deletes
it, which can break the code. This sniff skips the whole file while a `function` or `const` keyword after `use` isn't in
lower case. `Universal.UseStatements.LowercaseFunctionConst` reports that as an error and fixes it, and on `phpcbf`'s
next pass the import is read correctly. Everything else is left to the parent. It can be removed once Slevomat fixes the
check.

**Auto-fixable:** yes, as the parent.

---

## Jumbo.NamingConventions.ValidNamespaceName

Requires a namespace's name to be in ASCII, as `NonAscii`.

**Why:** PER Coding Style 3.1 has no rule for this, so this is an extra rule. PHP accepts any byte from `\x80` in a
name, so any Unicode character, including invisible ones and look-alike letters, such as a Cyrillic `а` for a Latin
`a`, and two names can look the same and be different. An accent can also be one character or a letter and a
combining mark. Jumbo's other naming sniffs already reject a non-ASCII class, function, method, property, constant or
variable name, as their case patterns only allow ASCII; this covers a namespace. It widens php-cs-fixer's
`no_homoglyph_names`.

**Auto-fixable:** no. There's no safe transliteration, and renaming one use of a namespace breaks the others.

**Good:**

```php
namespace Vendor\Cafe;
```

**Bad:**

```php
namespace Vendor\Café;
```

---

## Jumbo.NamingConventions.ValidVariableName

Warns about a variable or parameter whose name starts with an underscore, inside a class, enum, trait or interface.

**Why:** `Squiz.NamingConventions.ValidVariableName` reports a leading underscore as an error outside a class. Inside
one, it strips the underscore before checking camelCase, because the Squiz convention prefixes private members with one.

**Auto-fixable:** no. Renaming a variable is a refactor, not a fix.

This sniff fills only that gap. It doesn't report:

- Superglobals, such as `$_GET` and `$_SERVER`.
- A name that Squiz already reports, because the rest of it isn't camelCase either, such as `$_user_name`.
- Properties, including promoted constructor parameters, and static property access (`self::$_count`).
  `PERCS31.Classes.PropertyDeclaration` checks those where they're declared.

**Good:**

```php
class Order
{
    /**
     * @param list<int> $items
     */
    public function total(array $items): int
    {
        $sum = 0;
        foreach ($items as $item) {
            $sum += $item;
        }

        return $sum;
    }
}
```

**Bad:**

```php
class Order
{
    public function total(array $_items): int
    {
        $_sum = 0;
        foreach ($_items as $_item) {
            $_sum += $_item;
        }

        return $_sum;
    }
}
```

---

## Jumbo.PHP.BuiltInNameCase

Requires names PHP defines to be written in their own case:

- **`ClassNameCase`:** a built-in class, interface or enum, wherever it's referenced or imported: `new \datetime()`
  becomes `new \DateTime()`, and `use random\randomizer;` becomes `use Random\Randomizer;`.
- **`MagicMethodCase`:** a magic method, where it's declared and where it's called with `->`, `?->` or `::`:
  `__tostring()` becomes `__toString()`, and `__CONSTRUCT()` becomes `__construct()`.

Each class name is resolved as PHP resolves it, through `use` statements and the current namespace, and matched against
the classes built into the PHP running PHP_CodeSniffer, so a project class is never touched. A class from an extension
that isn't loaded there is missed. An alias, as in `use DateTime as Moment`, is left alone.

**Why:** PER Coding Style 3.1 has no rule for this, so this is an extra rule. PHP ignores the case of class and method
names, but a reader and a search don't, and the documented case is what everyone knows. These are php-cs-fixer's
`class_reference_name_casing` and Laravel Pint's `magic_method_casing`. Functions are covered by
`Squiz.PHP.LowercasePHPFunctions`.

**Auto-fixable:** yes.

**Good:**

```php
$date = new \DateTime();
return $this->__toString();
```

**Bad:**

```php
$date = new \datetime();
return $this->__tostring();
```

---

## Jumbo.PHP.DisallowInvisibleCharacters

Disallows any invisible or non-printable character except a space and a line feed, as `InvisibleCharacter`. By
Unicode category, that's:

- Control characters (`Cc`) except a line feed: a tab, carriage return, form feed, NUL and the rest.
- Format characters (`Cf`): a zero-width space, joiner or non-joiner, a soft hyphen, a byte order mark inside the
  file, and the bidirectional controls used in "Trojan Source" attacks.
- Separators (`Zs`, `Zl`, `Zp`) except a space: a no-break, thin or em space, and the line and paragraph separators.

A byte order mark at the very start of the file is left to `Generic.Files.ByteOrderMark`.

**The fix depends on where the character is:**

- **A double-quoted string or a heredoc:** an escape, such as `\t`, `\r`, `\0`, `\x1F` or `\u{00A0}`, which keeps the
  value. One inside or straight after an interpolated variable is reported only.
- **A single-quoted string or a nowdoc:** these can't hold an escape, so the string becomes double-quoted, or the
  nowdoc a heredoc, if it has no `$` or `\` that would then mean something. Otherwise it's reported only, as is a
  single-quoted string over several lines.
- **Code or a comment:** a space, or nothing for a character with no width (a format character). A tab becomes spaces
  to the next tab stop, and a carriage return before a line feed is removed, so the fixes agree with
  `Generic.WhiteSpace.DisallowTabIndent` and the line endings sniff, which report some of the same characters.
- **A name or inline HTML:** reported only, as a fix would change the name, or the output.

**Why:** an invisible character can hide what code does. A bidirectional control can make code read differently from
how it runs, and a no-break or zero-width space looks like a space, or like nothing, in an editor and a diff. No
inherited sniff covers them all: `Generic.WhiteSpace.DisallowTabIndent` only checks indents, and the line endings sniff
only line ends.

**Auto-fixable:** mostly.

**Good:**

```php
$label = "Total:\u{00A0}{$total}";
```

**Bad:**

```php
$label = "Total: {$total}"; // A no-break space after the colon.
```

**Configuration:**

| Property    | Type | Default | Description                                                                 |
| ----------- | ---- | ------- | --------------------------------------------------------------------------- |
| `allowTabs` | bool | false   | Allow a tab outside a string, to indent with tabs. A tab in a string isn't. |

---

## Jumbo.Operators.IncrementStyle

Requires a standalone increment or decrement after its variable: `$i++;`, not `++$i;`, as `PreIncrement`. Standalone
means a statement of its own, or a `for` loop's step, where its value isn't used, so the two forms do the same. One
whose value is used, as in `$x = ++$i`, is left alone.

**Why:** PER Coding Style 3.1 has no rule for this, so this is an extra rule. One form reads the same everywhere. This
is Laravel Pint's `increment_style`, with the post form.

**Auto-fixable:** yes.

**Good:**

```php
for ($i = 0; $i < 10; $i++) {
    $count--;
}
```

**Bad:**

```php
for ($i = 0; $i < 10; ++$i) {
    --$count;
}
```

---

## Jumbo.PHP.EmbeddedPhp

Sets the layout of a multi-line PHP block in a file with markup. This is a fork of `Squiz.PHP.EmbeddedPhp`.

- Nothing comes after `<?php` on its line, and nothing comes before `?>` on its line, except an indent.
- With nothing but an indent before `<?php`, the code directly inside is level with the tag.
- With markup before `<?php`, the code is indented one level past that line.
- `?>` is level with the line `<?php` is on. Markup may follow it.
- A tag on one line is exempt, such as `<?php foo(); ?>` or `<?php declare(strict_types=1) ?>`, unless it's empty, as
  in `<?php ?>`, or only echoes. A last block with no `?>` is exempt too.
- An empty block is removed, whether it's on one line or several.
- A short echo tag must be on one line, with no semicolon before its `?>`, which is redundant there:
  `<?= $x; ?>` becomes `<?= $x ?>` (`ShortEchoSemicolon`).
- A tag on one line that holds only an `echo` becomes a short echo tag: `<?php echo $x; ?>` becomes `<?= $x ?>`
  (`ShortEchoTag`), as php-cs-fixer's `echo_tag_syntax` does with `format: short`. A tag with more stays `<?php`, as
  `Universal.PHP.OneStatementInShortEchoTag` requires. `<?=` is always available, since PHP 5.4.
- A blank line just inside the tags is allowed.

**Why:** PER doesn't set this layout. The PERCS31 standard allows the code level with the tags, or one level past them.
The Squiz sniff skips a file's first tag, so a file with one block wouldn't be checked, and it requires a semicolon that
PER's own `<?php declare(strict_types=1) ?>` doesn't have. Its fix also splits markup from the tags, which changes the
HTML's whitespace.

**Auto-fixable:** yes, except a short echo tag over several lines, as the right rewrite is a judgement call.

**Good:**

```php
<div>
    <?php
    if ($a) {
        foo();
    }
    ?>
    <p><?= $b ?></p>
    <p>Aged <span><?php
        $age = compute($user);
        echo $age;
    ?></span></p>
</div>
```

**Bad:**

```php
<div>
    <?php if ($a) {
        foo();
    } ?>
    <?php
        bar();
    ?>
    <p><?= foo(
        $b,
    ) ?></p>
</div>
```

---

## Jumbo.PHP.FunctionCalls

Reports calls to PHP's functions that have a better form, and fixes them where the fix is certain to keep what the code
does. Only calls to PHP's global functions are checked: an unqualified name that a `use function` statement imports
from elsewhere, or that the file declares a function by, is left alone.

| Code                   | Reported                                                     | Fixed to                                    |
| ---------------------- | ------------------------------------------------------------ | ------------------------------------------- |
| `CastFunction`         | `strval()`, `intval()`, `floatval()`, `boolval()`            | A cast, as in `(int) $a`                    |
| `SetType`              | `settype($a, 'int');`, as a statement, with a literal type   | `$a = (int) $a;`                            |
| `FunctionAlias`        | An alias, such as `sizeof()`, `join()` or `is_integer()`     | The main name: `count()`, `implode()` ...   |
| `DieAlias`             | `die`                                                        | `exit`                                      |
| `PrintStatement`       | `print 'x';`, as a statement                                 | `echo 'x';`                                 |
| `DedicatedIniFunction` | `ini_set()` for a setting with its own function              | The function, as a statement with a literal |
| `ModernStringFunction` | `strpos($h, $n) !== false`, `=== 0` and the like             | `str_contains()`, `str_starts_with()` ...   |
| `ArrayFirstLast`       | `$a[array_key_first($a)]`, with `php_version` 8.5 or later   | `array_first($a)`                           |
| `StringLength`         | `strlen($a) === 0`, `!== 0` or `> 0`                         | `$a === ''` or `$a !== ''`                  |
| `ArrayPush`            | `array_push($a, $b);` with one value, as a statement         | `$a[] = $b;`                                |
| `ImplodeSeparator`     | `implode($pieces)`                                           | `implode('', $pieces)`                      |
| `UselessSprintf`       | `sprintf('text')`, with no `%`                               | `'text'`                                    |
| `UselessPrintf`        | `printf('text');`, with no `%`, as a statement               | `echo 'text';`                              |
| `Pow`                  | `pow($a, $b)`                                                | `$a ** $b`                                  |
| `CallUserFunc`         | `call_user_func()` and `call_user_func_array()`              | A direct call, as in `$callback($a)`        |
| `FopenTextMode`        | A warning for `t` in a literal `fopen()` mode                | Not fixed                                   |

- **Casts:** a call with a base, as in `intval($a, 16)`, or a first-class callable, is left alone. An argument that
  isn't simple gets parentheses: `(string) ($a + 1)`.
- **Aliases:** from [PHP's list of function aliases](https://www.php.net/manual/en/aliases.php).
- **`ini_set()`:** for `max_execution_time` (`set_time_limit()`), `error_reporting`, `date.timezone`
  (`date_default_timezone_set()`), `include_path` (`set_include_path()`), `bcmath.scale` (`bcscale()`),
  `ignore_user_abort`, `mbstring.language`, `.substitute_character` and `.regex_encoding`, and `session.name`,
  `.save_path`, `.cache_limiter` and `.cookie_*`. It's only fixed as a statement, with a literal value converted to the
  function's type, as `ini_set()` returns the old value as a string, where the functions return other things, and
  takes a string, where `set_time_limit('30')` is a `TypeError` under strict types. A cookie setting is reported only.
- **Modern functions:** only patterns whose fix keeps the behaviour: strict comparisons only, and
  `str_ends_with()` only for a non-empty literal needle, as `substr($h, -strlen(''))` is all of `$h`. A comparison
  inside a tighter operator, as in `1 + strpos($h, $n) !== false`, isn't touched. `array_first()` and `array_last()`
  need PHP 8.5, so `php_version` must be set, as in `<config name="php_version" value="80500"/>`.
- **`pow()`:** an operand that isn't simple gets parentheses, as `**` binds tighter than nearly everything:
  `pow(-2, 2)` becomes `(-2) ** 2`. `pow($a, $b) ** $c` keeps its order, as `($a ** $b) ** $c`.
- **`call_user_func()`:** a string callable becomes a call to that function, fully qualified in a namespace, an array
  of a variable and a method name becomes `$object->method()`, and a variable becomes `$callable()`.
  `call_user_func_array()` unpacks its array. A call where an argument assigns to the callable's variable is left
  alone.
- **`fopen()`:** `t` is Windows-only text mode, which translates line endings there. Removing it changes what the
  code does on Windows, so it isn't fixed. `b` is PHP's default everywhere, so it isn't checked.

**Why:** PER Coding Style 3.1 has no rules for which functions to call, so this is an extra rule. These are
php-cs-fixer's `set_type_to_cast`, `no_alias_language_construct_call`, `no_mixed_echo_print`, `modernize_strpos`,
`string_length_to_empty`, `array_push`, `implode_call`, `no_useless_sprintf`, `no_useless_printf`,
`pow_to_exponentiation`, `regular_callable_call` and `fopen_flags`, with php-collective's `PreferCastOverFunction` and
`RemoveFunctionAlias`, which this replaces, in one sniff, so a call goes straight to its final form.

**Auto-fixable:** mostly.

**Good:**

```php
$count = (int) $input;
if (str_contains($name, ' ')) {
    $parts[] = $name;
}
```

**Bad:**

```php
$count = intval($input);
if (strpos($name, ' ') !== false) {
    array_push($parts, $name);
}
```

---

## Jumbo.PHP.IntegerLiteral

Checks how an integer literal is written. Both fixes keep the value.

- **`LiteralCase`:** the prefix in lower case and hex digits in upper case: `0XFF` and `0xff` become `0xFF`, `0B101`
  becomes `0b101`, and `0O17` becomes `0o17`.
- **`OctalPrefix`:** a legacy octal literal, `0755`, becomes `0o755`, the explicit prefix PHP 8.1 added. Only when
  PHP_CodeSniffer's `php_version` is 8.1 or later, as in `<config name="php_version" value="80100"/>`, as the project
  may support an older PHP.

**Why:** PER Coding Style 3.1 has no rule for number literals, so this is an extra rule. A lower-case `b`, `o` or `x`
stands out from both the leading `0` and the digits, as in `0xDEADBEEF`, and `0o755` can't be mistaken for a decimal
number. These are php-cs-fixer's `integer_literal_case` and `octal_notation`.

**Auto-fixable:** yes.

**Good:**

```php
$mask = 0xFF;
$mode = 0o755;
```

**Bad:**

```php
$mask = 0xff;
$mode = 0755;
```

---

## Jumbo.PHP.SimplifySyntax

Requires the simpler of two ways to write the same thing, where both behave the same:

| Code                | Bad                         | Good                       |
| ------------------- | --------------------------- | -------------------------- |
| `CanSimplify`       | `for (; $i < 10;)`          | `while ($i < 10)`          |
| `Infinite`          | `for (;;)`                  | `while (true)`             |
| `ConsecutiveIssets` | `isset($a) && isset($b)`    | `isset($a, $b)`            |
| `ConsecutiveUnsets` | `unset($a);` `unset($b);`   | `unset($a, $b);`           |
| `NullsafeThis`      | `$this?->name`              | `$this->name`              |
| `BinaryString`      | `b'abc'`                    | `'abc'`                    |
| `UnneededBraces`    | `{ foo(); }`, on its own    | `foo();`                   |
| `UselessReturn`     | `return;` at a function end | nothing                    |

- **`isset()`:** only calls that are whole operands of `&&`, so `!isset($a) && isset($b)` and
  `isset($a) && isset($b) === $c` are left alone.
- **`unset()`:** only statements with nothing between them, not even a comment.
- **Braces:** a block that isn't a control structure's, function's or class's, including one after `case 1:`. A block
  with a function or class declared in it is left alone, as the braces make the declaration conditional.
- **`return;`:** only the last statement of a function or closure. One that ends a branch early is left alone.

**Why:** PER Coding Style 3.1 has no rule for these, so this is an extra rule. Each pair behaves the same, so the
shorter form says it with less to read: `$this` is never null, the `b` prefix has done nothing since PHP 6 was
dropped, and braces don't make a scope in PHP. These are Generic.CodeAnalysis.ForLoopShouldBeWhileLoop, which it
replaces, with a fix, php-cs-fixer's `empty_loop_condition`, `combine_consecutive_issets`,
`combine_consecutive_unsets` and `no_useless_nullsafe_operator`, and Laravel Pint's `no_binary_string`,
`no_unneeded_braces` and `no_useless_return`.

**Auto-fixable:** yes, unless a comment is in the code the fix would remove. The fix for braces leaves the block's
code indented as it was, for the indent sniff to fix.

**Good:**

```php
while ($i < 10) {
    unset($a[$i], $b[$i]);
}
```

**Bad:**

```php
for (; $i < 10;) {
    unset($a[$i]);
    unset($b[$i]);
}
```

---

## Jumbo.PhpUnit.RequireDedicatedAssertions

Requires PHPUnit's dedicated assertions, in a test class:

- `assertNull($a)`, `assertTrue($a)` or `assertFalse($a)`, not `assertSame()` with `null`, `true` or `false`, as
  `InvalidAssert`.
- An assertion of its own, not `assertTrue()` or `assertFalse()` of a check, as `DedicatedAssertion`:
  `assertIsInt($a)` or `assertIsNotInt($a)` for `is_int()`, and the same for the other `is_*()` type checks,
  `assertNull()` for `is_null()`, `assertNan()`, `assertFinite()` and `assertInfinite()`, `assertFileExists()` for
  `file_exists()`, `assertDirectoryExists()` for `is_dir()`, `assertIsReadable()` and `assertIsWritable()`, and
  `assertArrayHasKey($k, $a)` for `array_key_exists($k, $a)`.

Only exact equivalents are fixed: `is_nan()` and the like have no negative assertion, `is_resource()` differs on a
closed resource, `is_file()` isn't `assertFileExists()`, and `empty()` (which differs on an undefined variable) and
`in_array()` (loose, where `assertContains()` is strict) aren't checked. The check must be the assertion's whole first
argument, so `assertTrue(!is_int($a))` and `assertTrue(is_int($a) && $b)` are left alone.

A test class is one that extends `PHPUnit\Framework\TestCase`. The `DedicatedAssertion` check covers `$this->`,
`self::` and `static::` calls.

**Why:** PER Coding Style 3.1 has no rules for tests, so this is an extra rule. A dedicated assertion fails with a
clearer message: "Failed asserting that 'abc' is of type int", not "Failed asserting that false is true". It extends
`PhpCollective.Testing.AssertPrimitives`, which it replaces in Jumbo, and adds php-cs-fixer's
`php_unit_dedicate_assert`. The parent only checks files named `*Test.php`.

**Auto-fixable:** yes.

**Good:**

```php
$this->assertNull($result);
$this->assertIsInt($count);
```

**Bad:**

```php
$this->assertSame(null, $result);
$this->assertTrue(is_int($count));
```

---

## Jumbo.PhpUnit.TestClass

Checks a PHPUnit test class's declarations:

- **No PHPUnit annotations** (`TestAnnotation` and `Annotation`). PHPUnit 12 reads attributes only, so an annotation
  is silently ignored: a method marked only `@test` never runs, and `@dataProvider` or `@depends` does nothing.
  - `@test` is fixed to a `test` prefix on the method's name, as in `testAddsNumbers()`, and a `#[Depends]` or
    `@depends` naming the method in the class is renamed too. It's not fixed if the new name is taken.
  - Any other is fixed to its attribute, written fully qualified for
    `SlevomatCodingStandard.Namespaces.ReferenceUsedNamesOnly` to import: `@dataProvider provideCases` becomes
    `#[DataProvider('provideCases')]`, `@requires PHP 8.4` becomes `#[RequiresPhp('>= 8.4')]`, and so on, as
    php-cs-fixer's `php_unit_attributes` does. `@coversDefaultClass` has no attribute, so it's removed, and a
    class-level `@covers ::method` names that class.
  - One with no attribute where it is, such as `@covers` on a method, or whose value can't be read, is reported but
    not fixed.
- **`setUp()`, `tearDown()`, `setUpBeforeClass()` and `tearDownAfterClass()` `protected`** (`HookVisibility`), not
  `public`, as PHPUnit's `TestCase` declares them.

A test class is one that extends `PHPUnit\Framework\TestCase`, resolved through the file's `use` statements. A class
that extends a project's own base test class isn't checked. `@author`, which PHPUnit also reads as a group, is left
alone, as it's a general doc tag.

**Why:** PER Coding Style 3.1 has no rules for tests, so this is an extra rule. An annotation PHPUnit ignores is a bug
waiting to be found. No inherited sniff checks either.

**Auto-fixable:** mostly.

**Good:**

```php
#[CoversClass(Calculator::class)]
final class CalculatorTest extends TestCase
{
    protected function setUp(): void {}

    #[DataProvider('provideCases')]
    public function testAddsNumbers(int $a): void {}
}
```

**Bad:**

```php
/**
 * @covers \Vendor\Calculator
 */
final class CalculatorTest extends TestCase
{
    public function setUp(): void {}

    /**
     * @test
     * @dataProvider provideCases
     */
    public function addsNumbers(int $a): void {}
}
```

---

## Jumbo.Strings.RequireBracedVariables

Requires braces around every variable interpolated in a double-quoted string or a heredoc, as in `"Hi {$name}"`.

**Why:** braces show exactly what's interpolated. Without them, `"$className::create()"` takes the class name and
leaves `::create()` as text, while `"$user->name"` takes the property read too. PER has no rule for it, so this is an
extra rule, in Jumbo only. It's the same rule as PHP-CS-Fixer's `explicit_string_variable`. MO4's
`Strings.VariableInDoubleQuotedString` makes the same check with a regular expression, whose fix wraps an escaped
`\$price` and turns `"$row[id]"` into `"{$row[id]}"`, where `id` is a constant. This sniff uses PHP's own tokenizer.

A variable takes any property read or array access written after it. An unquoted array key is a string there, so it's
quoted. `${name}` is left alone, as `SlevomatCodingStandard.Strings.DisallowVariableParsing` reports it.

| Code            | Reported for                          |
| --------------- | ------------------------------------- |
| `MissingBraces` | A variable in a string without braces |

**Auto-fixable:** yes.

**Good:**

```php
$a = "Hi {$name}, you're {$user->age}, ID {$row['id']}.";
$a = "Escaped \$price, and {$total}.";
```

**Bad:**

```php
$a = "Hi $name, you're $user->age, ID $row[id].";
```

**Configuration:** none.

---

## Jumbo.TypeHints.DeclareStrictTypes

Requires `declare(strict_types=1)` at the top of each file. This replaces
`SlevomatCodingStandard.TypeHints.DeclareStrictTypes`.

**Why:** the Slevomat sniff only reads the first declare statement, and its fix adds `strict_types` to it, which in a
block `declare` is a fatal error. Its other checks are already made by PERCS31's sniffs. This sniff:

- Accepts `strict_types=1` in any of the declare statements at the top of the file, in any case, as PHP does, as in
  `declare(ticks=1);` then `declare(strict_types=1);`.
- Checks a template with only `<?=` tags too.
- Leaves the rest to PERCS31. [`PERCS31.Files.DeclareStatement`](PERCS31_Custom_Sniffs.md#percs31filesdeclarestatement)
  reports spaces inside the parentheses, and a directive that isn't in lower case.
  [`PERCS31.Files.FileHeader`](PERCS31_Custom_Sniffs.md#percs31filesfileheader) checks the blank lines around the
  declare statements, and that in a file with markup, the declaration is on the first line, as in
  `<?php declare(strict_types=1) ?>`. [PER Coding Style 3.1, Section 3](https://www.php-fig.org/per/coding-style/#3-declare-statements-namespace-and-import-statements) says that in a file containing markup
  outside PHP tags, the declaration "MUST be on the first line".

`strict_types=0` is reported as `DeclareStrictTypesDisabled`, and a missing declaration as `DeclareStrictTypesMissing`.

**Auto-fixable:** yes. The fix for `strict_types=0` sets it to 1. The fix for a missing declaration adds
`declare(strict_types=1);` as a statement of its own:

- Before any other declare statements, never as a directive in one.
- Otherwise after the file docblock, as the header's order is the opening tag, the file docblock, then the declare
  statements.
- In a file with markup, or a template with only `<?=` tags, as `<?php declare(strict_types=1) ?>` on the first line.
  PHP skips a shebang line, so in a file starting with one, on the second line.

---

## Jumbo.TypeHints.ParameterTypeHint

Requires a type hint on each parameter, skipping a function with a hook on a promoted constructor parameter. This
replaces `SlevomatCodingStandard.TypeHints.ParameterTypeHint`.

**Why:** the parent treats every variable between a function's parentheses as a parameter, so `$value` or `$this` in a
hook on a promoted constructor parameter is reported as a parameter with no type hint. The other parameters of that
function aren't checked either. [PER Coding Style 3.1, Section 4.9](https://www.php-fig.org/per/coding-style/#49-property-hooks) allows a single short hook there, so this sniff skips
a function with one, and leaves everything else to the parent. See [`PERCS31.Classes.PropertyHooks`](PERCS31_Custom_Sniffs.md#percs31classespropertyhooks).

**Auto-fixable:** yes, as the parent.

---

## Jumbo.TypeHints.ParameterTypeHintSpacing

Checks the spacing around parameter type hints, skipping a function with a hook on a promoted constructor parameter.
This replaces `SlevomatCodingStandard.TypeHints.ParameterTypeHintSpacing`.

**Why:** the parent treats every variable between a function's parentheses as a parameter, so `$value` or `$this` in a
hook on a promoted constructor parameter is reported as a parameter with bad spacing. The other parameters of that
function aren't checked either. [PER Coding Style 3.1, Section 4.9](https://www.php-fig.org/per/coding-style/#49-property-hooks) allows a single short hook there, so this sniff skips
a function with one, and leaves everything else to the parent. See [`PERCS31.Classes.PropertyHooks`](PERCS31_Custom_Sniffs.md#percs31classespropertyhooks).

**Auto-fixable:** yes, as the parent.

---

## Jumbo.WhiteSpace.BlockBlankLines

Disallows a blank line straight after the opening brace, or straight before the closing brace, of a block `declare`, a
property's hook list and a hook's body. A blank line between two statements, or between two hooks, isn't reported.

**Why:** PER only rules these blank lines out for a class and a control structure, and at the end of a function's body.
It has no rule for a block `declare` or a property hook, so the [PERCS31](PERCS31.md) standard allows them. This is an extra
rule, so that every block in braces starts and ends the same way. See
[Blank Lines in a Block `declare`](PER-CS_3.1_Judgement_Calls.md#blank-lines-in-a-block-declare) and
[Blank Lines Inside a Hook's Braces](PER-CS_3.1_Judgement_Calls.md#blank-lines-inside-a-hooks-braces).

No inherited sniff covers these blocks. `Squiz.WhiteSpace.ControlStructureSpacing` has a fixed list of control
structures, without `declare`, and PHP_CodeSniffer doesn't read a hook's braces as a scope. The start of a function's
body is checked by `Squiz.WhiteSpace.FunctionOpeningBraceSpace`.

**Auto-fixable:** yes.

**Good:**

```php
public string $name {
    get {
        return $this->name;
    }

    set {
        $this->name = trim($value);
    }
}
```

**Bad:**

```php
public string $name {

    get {

        return $this->name;

    }

}
```

---

## Jumbo.WhiteSpace.DocBlockSpacing

Disallows a blank line between a doc comment and the declaration it's for: a class, interface, trait, enum, function,
method, property, constant or enum case. The fix removes it. A local variable's inline `@var` isn't checked.

**Why:** PER Coding Style 3.1 has no rule for doc comments, so this is an extra rule. It extends
`PhpCollective.WhiteSpace.DocBlockSpacing`, which only checks a class, interface, trait or function. Its tokens are set
in code, which a ruleset can't change, so this only adds the others. With an attribute between the doc comment and the
declaration, the attribute's line is the one checked.

**Auto-fixable:** yes.

**Good:**

```php
/** The largest batch. */
public const int LIMIT = 100;
```

**Bad:**

```php
/** The largest batch. */

public const int LIMIT = 100;
```

---

## Jumbo.WhiteSpace.LanguageConstructSpacing

Requires a single space after a language construct, with no line break: `echo`, `print`, `return`, `include`,
`include_once`, `require`, `require_once`, `yield`, `yield from`, `throw`, `namespace`, `use` and `goto`. This replaces
`Generic.WhiteSpace.LanguageConstructSpacing`.

**Why:** PER has no such rule, so `return` then a line break is allowed in the [PERCS31](PERCS31.md) standard. The Generic sniff also
checks `new`, which PER's section 8 puts on its own line before an anonymous class's attributes, and its fix turns
`(yield)` into `(yield )`. This sniff skips both, and leaves everything else to the parent. A comment after a construct
isn't reported, as the parent leaves it alone.

**Auto-fixable:** yes, as the parent.

**Good:**

```php
return $total;
```

**Bad:**

```php
return
    $total;
```
