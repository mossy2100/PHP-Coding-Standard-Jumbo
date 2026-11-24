# Jumbo Inherited Sniffs

The sniffs the [Jumbo](Jumbo.md) standard adds on top of [PERCS31](PERCS31.md), from PHP_CodeSniffer's Generic, Squiz and Zend standards, from
[Slevomat](https://github.com/slevomat/coding-standard), from [PHPCSExtra](https://github.com/PHPCSStandards/PHPCSExtra) (`Universal`) and from [php-collective/code-sniffer](https://github.com/php-collective/code-sniffer). They're extra rules, not PER rules. Each
section summarises what the sniff checks in Jumbo.

Where Jumbo changes a sniff PERCS31 already uses, see [PERCS31](Jumbo.md#percs31). The sniffs written for Jumbo are in [Jumbo Custom Sniffs](Jumbo_Custom_Sniffs.md).
Some Slevomat sniffs here are used through a Jumbo sniff that extends them, to fix a bug or skip a case, and their
sections link to it.

| Sniff                                                                                    | Checks                                                         | Fixable |
|------------------------------------------------------------------------------------------|----------------------------------------------------------------|---------|
| `Generic.Classes.DuplicateClassName`                                                     | A warning for a class name declared twice                      | No      |
| `Generic.CodeAnalysis.AssignmentInCondition`                                             | A warning for an assignment in a condition                     | No      |
| `Generic.CodeAnalysis.EmptyStatement`                                                    | No empty control structure body                                | No      |
| `Generic.CodeAnalysis.ForLoopShouldBeWhileLoop`                                          | A warning for a `for` loop with only a condition               | No      |
| `Generic.CodeAnalysis.RequireExplicitBooleanOperatorPrecedence`                          | Parentheses where `&&` and `\|\|` are mixed                    | No      |
| `Generic.CodeAnalysis.UnconditionalIfStatement`                                          | A warning for an `if` that is only `true` or `false`           | No      |
| `Generic.CodeAnalysis.UnnecessaryFinalModifier`                                          | A warning for `final` on a method of a final class             | No      |
| `Generic.CodeAnalysis.UnusedFunctionParameter`                                           | A warning for an unused parameter                              | No      |
| `Generic.CodeAnalysis.UselessOverridingMethod`                                           | A warning for a method that only calls its parent              | No      |
| `Generic.Commenting.DocComment`                                                          | A doc comment's summary, spacing and tag groups                | Partly  |
| `Generic.Files.ExecutableFile`                                                           | No executable PHP file                                         | No      |
| `Generic.Metrics.NestingLevel`                                                           | Blocks nested no more than 5 deep                              | No      |
| `Generic.PHP.DeprecatedFunctions`                                                        | No deprecated native functions                                 | No      |
| `Generic.PHP.DisallowRequestSuperglobal`                                                 | No `$_REQUEST`                                                 | No      |
| `Generic.PHP.DiscourageGoto`                                                             | A warning for `goto`                                           | No      |
| `Generic.PHP.ForbiddenFunctions`                                                         | No `extract()`                                                 | No      |
| `Generic.VersionControl.GitMergeConflict`                                                | No merge conflict markers                                      | No      |
| `Generic.WhiteSpace.ArbitraryParenthesesSpacing`                                         | No space inside grouping parentheses                           | Yes     |
| `Generic.WhiteSpace.HereNowdocIdentifierSpacing`                                         | No space in `<<< END`                                          | Yes     |
| `Modernize.FunctionCalls.Dirname`                                                        | `__DIR__`, not `dirname(__FILE__)`                             | Yes     |
| `PhpCollective.Commenting.DocBlockReturnSelf`                                            | `@return $this` where every `return` is `return $this;`        | Yes     |
| `PhpCollective.Commenting.DocBlockReturnTag`                                             | No `@return` description starting with a variable              | No      |
| `PhpCollective.Commenting.DocBlockStructure`                                             | `/**` and `*/`, not `/***` or `**/`                            | Yes     |
| `PhpCollective.Commenting.DocBlockTagIterable`                                           | The spacing inside a generic doc type                          | Yes     |
| `PhpCollective.Commenting.InlineDocBlock`                                                | An inline `@var` in a doc comment, with its type first         | Yes     |
| `PhpCollective.ControlStructures.UnneededElse`                                           | No `else` after branches that all exit early                   | Yes     |
| `PhpCollective.PHP.NoIsNull`                                                             | `$x === null`, not `is_null($x)`                               | Yes     |
| `PhpCollective.PHP.NotEqual`                                                             | `!=`, not `<>`                                                 | Yes     |
| `PhpCollective.PHP.PreferCastOverFunction`                                               | A cast, not `intval()` and similar                             | Yes     |
| `PhpCollective.PHP.RemoveFunctionAlias`                                                  | No function aliases, such as `sizeof()`                        | Yes     |
| `PhpCollective.Testing.AssertPrimitives`                                                 | `assertNull()`, not `assertSame(null, ...)`                    | Yes     |
| `PhpCollective.WhiteSpace.DocBlockSpacing`                                               | No blank line after a doc comment                              | Yes     |
| `PhpCollective.WhiteSpace.EmptyLines`                                                    | No more than one blank line in a row                           | Yes     |
| `SlevomatCodingStandard.Arrays.ArrayAccess`                                              | No white space around an array access's brackets               | Partly  |
| `SlevomatCodingStandard.Arrays.DisallowImplicitArrayCreation`                            | No array created by assigning to an undefined variable         | No      |
| `SlevomatCodingStandard.Arrays.DisallowPartiallyKeyed`                                   | Keys on all of an array's values, or none                      | No      |
| `SlevomatCodingStandard.Attributes.AttributesOrder`                                      | Attributes in alphabetical order                               | Yes     |
| `SlevomatCodingStandard.Classes.ClassLength`                                             | A class of no more than 1000 lines of code                     | No      |
| `SlevomatCodingStandard.Classes.ClassMemberSpacing`                                      | The spacing between class members                              | Partly  |
| `SlevomatCodingStandard.Classes.ConstantSpacing`                                         | The blank lines after a constant, through a Jumbo sniff        | Yes     |
| `SlevomatCodingStandard.Classes.DisallowStringExpressionPropertyFetch`                   | No `$a->{'b'}` where `$a->b` works                             | Yes     |
| `SlevomatCodingStandard.Classes.EmptyLinesAroundClassBraces`                             | No blank lines inside a class's braces, through a Jumbo sniff  | Yes     |
| `SlevomatCodingStandard.Classes.EnumCaseSpacing`                                         | The blank lines after an enum case, through a Jumbo sniff      | Yes     |
| `SlevomatCodingStandard.Classes.MethodSpacing`                                           | The spacing between methods                                    | Yes     |
| `SlevomatCodingStandard.Classes.ModernClassNameReference`                                | `::class`, not `get_class()` and similar                       | Yes     |
| `SlevomatCodingStandard.Classes.PropertySpacing`                                         | The blank lines after a property, through a Jumbo sniff        | Yes     |
| `SlevomatCodingStandard.Classes.ReadonlyClass`                                           | `readonly class` when every property is readonly               | Yes     |
| `SlevomatCodingStandard.Classes.RequireConstructorPropertyPromotion`                     | A promoted constructor parameter where possible                | Yes     |
| `SlevomatCodingStandard.Classes.RequireMultiLineMethodSignature`                         | A long method signature split over lines                       | Yes     |
| `SlevomatCodingStandard.Classes.RequireSelfReference`                                    | `self::`, not the class's own name                             | Yes     |
| `SlevomatCodingStandard.Classes.RequireSingleLineMethodSignature`                        | A short method signature on one line, through a Jumbo sniff    | Yes     |
| `SlevomatCodingStandard.Classes.TraitUseOrder`                                           | Trait `use` statements in alphabetical order                   | Yes     |
| `SlevomatCodingStandard.Commenting.DeprecatedAnnotationDeclaration`                      | The `@deprecated` annotation format                            | No      |
| `SlevomatCodingStandard.Commenting.EmptyComment`                                         | No empty comments                                              | Yes     |
| `SlevomatCodingStandard.Commenting.RequireOneDocComment`                                 | One doc comment per declaration                                | No      |
| `SlevomatCodingStandard.Commenting.RequireOneLineDocComment`                             | A doc comment with one tag on one line                         | Yes     |
| `SlevomatCodingStandard.Commenting.UselessInheritDocComment`                             | No useless `@inheritDoc` comments                              | Yes     |
| `SlevomatCodingStandard.ControlStructures.DisallowContinueWithoutIntegerOperandInSwitch` | No `continue` without an operand in a `switch`                 | Yes     |
| `SlevomatCodingStandard.ControlStructures.LanguageConstructWithParentheses`              | No `echo(...)`, through a Jumbo sniff                          | Yes     |
| `SlevomatCodingStandard.ControlStructures.RequireMultiLineTernaryOperator`               | A long ternary split over lines                                | Yes     |
| `SlevomatCodingStandard.ControlStructures.RequireNullCoalesceEqualOperator`              | `??=` where possible                                           | Yes     |
| `SlevomatCodingStandard.ControlStructures.RequireNullCoalesceOperator`                   | `??` where possible                                            | Yes     |
| `SlevomatCodingStandard.ControlStructures.RequireNullSafeObjectOperator`                 | `?->` where possible                                           | Yes     |
| `SlevomatCodingStandard.ControlStructures.RequireShortTernaryOperator`                   | `?:` where possible, through a Jumbo sniff                     | Yes     |
| `SlevomatCodingStandard.ControlStructures.RequireTernaryOperator`                        | A ternary where possible                                       | Partly  |
| `SlevomatCodingStandard.ControlStructures.UselessIfConditionWithReturn`                  | No `if` that only returns `true` or `false`                    | Partly  |
| `SlevomatCodingStandard.ControlStructures.UselessTernaryOperator`                        | No `$a > $b ? true : false`                                    | Partly  |
| `SlevomatCodingStandard.Exceptions.DeadCatch`                                            | No unreachable `catch` blocks                                  | No      |
| `SlevomatCodingStandard.Exceptions.RequireNonCapturingCatch`                             | No variable on a `catch` that doesn't use it                   | Yes     |
| `SlevomatCodingStandard.Functions.FunctionLength`                                        | A function of no more than 100 lines                           | No      |
| `SlevomatCodingStandard.Functions.RequireArrowFunction`                                  | An arrow function for a closure that only returns              | Yes     |
| `SlevomatCodingStandard.Functions.RequireMultiLineCall`                                  | A long call split over lines                                   | Yes     |
| `SlevomatCodingStandard.Functions.RequireSingleLineCall`                                 | A short call on one line, through a Jumbo sniff                | Yes     |
| `SlevomatCodingStandard.Functions.StaticClosure`                                         | `static` on a closure that doesn't use `$this`                 | Yes     |
| `SlevomatCodingStandard.Functions.StrictCall`                                            | The strict argument in `in_array()` and similar                | No      |
| `SlevomatCodingStandard.Functions.UselessParameterDefaultValue`                          | No useless parameter default values                            | Yes     |
| `SlevomatCodingStandard.Namespaces.AlphabeticallySortedUses`                             | `use` statements in alphabetical order                         | Partly  |
| `SlevomatCodingStandard.Namespaces.DisallowGroupUse`                                     | No compound namespace declarations                             | No      |
| `SlevomatCodingStandard.Namespaces.MultipleUsesPerLine`                                  | One `use` statement per line                                   | No      |
| `SlevomatCodingStandard.Namespaces.NamespaceDeclaration`                                 | A namespace declaration's format                               | Partly  |
| `SlevomatCodingStandard.Namespaces.ReferenceUsedNamesOnly`                               | A `use` statement for every referenced name                    | Partly  |
| `SlevomatCodingStandard.Namespaces.RequireOneNamespaceInFile`                            | One namespace per file                                         | No      |
| `SlevomatCodingStandard.Namespaces.UnusedUses`                                           | No unused `use` statements, through a Jumbo sniff              | Yes     |
| `SlevomatCodingStandard.Namespaces.UseFromSameNamespace`                                 | No `use` statement from the same namespace                     | Yes     |
| `SlevomatCodingStandard.Namespaces.UselessAlias`                                         | No useless `use` aliases                                       | Yes     |
| `SlevomatCodingStandard.Operators.RequireCombinedAssignmentOperator`                     | `+=`, `-=` and the like, where possible                        | Partly  |
| `SlevomatCodingStandard.Operators.RequireOnlyStandaloneIncrementAndDecrementOperators`   | `++` and `--` only as standalone statements                    | No      |
| `SlevomatCodingStandard.PHP.DisallowDirectMagicInvokeCall`                               | No direct `__invoke()` calls                                   | Yes     |
| `SlevomatCodingStandard.PHP.RequireExplicitAssertion`                                    | An `assert()`, not an inline `@var`                            | Yes     |
| `SlevomatCodingStandard.PHP.ShortList`                                                   | `[]`, not `list()`                                             | Yes     |
| `SlevomatCodingStandard.PHP.UselessSemicolon`                                            | No useless semicolons                                          | Yes     |
| `SlevomatCodingStandard.Strings.DisallowVariableParsing`                                 | No variables parsed in strings                                 | No      |
| `SlevomatCodingStandard.TypeHints.ClassConstantTypeHint`                                 | Type hints on class constants                                  | Partly  |
| `SlevomatCodingStandard.TypeHints.DisallowArrayTypeHintSyntax`                           | `array<int>`, not `int[]`, in a doc comment                    | Yes     |
| `SlevomatCodingStandard.TypeHints.NullableTypeForNullDefaultValue`                       | A nullable type on a parameter that defaults to `null`         | Yes     |
| `SlevomatCodingStandard.TypeHints.ParameterTypeHint`                                     | Parameter type hints, through a Jumbo sniff                    | Partly  |
| `SlevomatCodingStandard.TypeHints.ParameterTypeHintSpacing`                              | The spacing around parameter type hints, through a Jumbo sniff | Yes     |
| `SlevomatCodingStandard.TypeHints.PropertyTypeHint`                                      | Property type hints                                            | Partly  |
| `SlevomatCodingStandard.TypeHints.ReturnTypeHint`                                        | Return type hints                                              | Partly  |
| `SlevomatCodingStandard.TypeHints.ReturnTypeHintSpacing`                                 | The spacing around return type hints                           | Yes     |
| `SlevomatCodingStandard.Variables.DisallowVariableVariable`                              | No variable variables, e.g. `$$var`                            | No      |
| `SlevomatCodingStandard.Variables.DuplicateAssignmentToVariable`                         | No duplicate assignments to a variable                         | No      |
| `SlevomatCodingStandard.Whitespaces.DuplicateSpaces`                                     | No run of spaces in code                                       | Yes     |
| `Squiz.Arrays.ArrayDeclaration`                                                          | No mix of keyed and unkeyed values in an array                 | No      |
| `Squiz.Classes.ClassFileName`                                                            | A file named after its class                                   | No      |
| `Squiz.Commenting.DocCommentAlignment`                                                   | A docblock's stars lined up, with one space after each         | Yes     |
| `Squiz.Commenting.EmptyCatchComment`                                                     | A comment in an empty `catch`                                  | No      |
| `Squiz.Commenting.InlineComment`                                                         | One space after `//`                                           | Yes     |
| `Squiz.NamingConventions.ValidFunctionName`                                              | A global function's name in camelCase                          | No      |
| `Squiz.NamingConventions.ValidVariableName`                                              | `$camelCase` variable, parameter and property names            | No      |
| `Squiz.Operators.IncrementDecrementUsage`                                                | `$i++`, not `$i += 1`                                          | No      |
| `Squiz.Operators.ValidLogicalOperators`                                                  | `&&` and `\|\|`, not `and` and `or`                            | No      |
| `Squiz.PHP.DisallowMultipleAssignments`                                                  | No `$a = $b = 1`                                               | No      |
| `Squiz.PHP.Eval`                                                                         | A warning for `eval()`                                         | No      |
| `Squiz.PHP.GlobalKeyword`                                                                | No `global` statement                                          | No      |
| `Squiz.PHP.InnerFunctions`                                                               | No function declared inside a function                         | No      |
| `Squiz.PHP.LowercasePHPFunctions`                                                        | A native function's name in lower case                         | Yes     |
| `Squiz.Scope.StaticThisUsage`                                                            | No `$this` in a static method                                  | No      |
| `Squiz.Strings.DoubleQuoteUsage.NotRequired`                                             | Single quotes, unless double quotes are needed                 | Yes     |
| `Squiz.WhiteSpace.FunctionOpeningBraceSpace.SpacingAfter`                                | No blank line after a function's opening brace                 | Yes     |
| `Squiz.WhiteSpace.OperatorSpacing`                                                       | Exactly one space around operators                             | Yes     |
| `Squiz.WhiteSpace.SemicolonSpacing`                                                      | No white space before a semicolon                              | Yes     |
| `Universal.Arrays.DuplicateArrayKey`                                                     | No key repeated in an array                                    | No      |
| `Universal.CodeAnalysis.ConstructorDestructorReturn`                                     | A warning for a value returned from a constructor              | No      |
| `Universal.CodeAnalysis.ForeachUniqueAssignment`                                         | No `foreach` with the same key and value variable              | Yes     |
| `Universal.CodeAnalysis.NoDoubleNegative`                                                | No `!!$x`                                                      | Yes     |
| `Universal.CodeAnalysis.NoEchoSprintf`                                                   | `printf()`, not `echo sprintf()`                               | Yes     |
| `Universal.CodeAnalysis.StaticInFinalClass`                                              | `self`, not `static`, in a final class                         | Yes     |
| `Universal.Constants.UppercaseMagicConstants`                                            | Magic constants in upper case, e.g. `__DIR__`                  | Yes     |
| `Universal.Files.SeparateFunctionsFromOO`                                                | Functions and classes in separate files                        | No      |
| `Universal.FunctionDeclarations.NoLongClosures`                                          | A closure of no more than 20 lines of code                     | No      |
| `Universal.OOStructures.AlphabeticExtendsImplements`                                     | An `implements` list in alphabetical order                     | Yes     |
| `Universal.PHP.NoFQNTrueFalseNull`                                                       | `true`, not `\true`                                            | Yes     |
| `Universal.PHP.OneStatementInShortEchoTag`                                               | One statement in a short echo tag                              | Yes     |
| `Zend.Files.ClosingTag`                                                                  | No closing tag at the end of a file                            | Yes     |

---

## Generic.Classes.DuplicateClassName

A warning for a class, interface, trait or enum name declared twice, across all the files in a run.

**Auto-fixable:** no.

---

## Generic.CodeAnalysis.AssignmentInCondition

Warns about assignments in conditions. See [Yoda-style Comparisons](../README.md#yoda-style-comparisons).

**Auto-fixable:** no.

---

## Generic.CodeAnalysis.EmptyStatement

Disallows an empty control structure body, such as `if ($a) {}`. Jumbo excludes `DetectedCatch`: an empty `catch` is
left to `Squiz.Commenting.EmptyCatchComment`, which allows one with a comment saying why.

**Auto-fixable:** no.

---

## Generic.CodeAnalysis.ForLoopShouldBeWhileLoop

A warning for a `for` loop with only a condition, such as `for (; $i < 10;)`, which can be a `while` loop.
`for (;;)` isn't reported.

**Auto-fixable:** no.

---

## Generic.CodeAnalysis.RequireExplicitBooleanOperatorPrecedence

Requires parentheses where `&&` and `||` are mixed, as in `($a && $b) || $c`, so the precedence is clear.

**Auto-fixable:** no.

---

## Generic.CodeAnalysis.UnconditionalIfStatement

A warning for an `if` or `elseif` whose condition is only `true` or `false`, usually debug code.

**Auto-fixable:** no.

---

## Generic.CodeAnalysis.UnnecessaryFinalModifier

A warning for `final` on a method of a final class, where it has no effect.

**Auto-fixable:** no.

---

## Generic.CodeAnalysis.UnusedFunctionParameter

A warning for a function or method parameter that the body never uses. Jumbo excludes these codes:

- The six `FoundInExtendedClass` and `FoundInImplementedInterface` codes: a class that extends a class or implements an
  interface is skipped, as a parent or the interface may fix the signature.
- `FoundBeforeLastUsed`: an unused parameter before a used one is allowed, as it can't be dropped without moving the
  used one, which a callback's caller may not allow, as in `fn($value, $key) => $key > 0`.

**Auto-fixable:** no.

---

## Generic.CodeAnalysis.UselessOverridingMethod

A warning for a method that only calls the parent method of the same name, with the same arguments. It doesn't
compare signatures, so an override that only widens visibility or narrows a type needs a `phpcs:ignore`.

**Auto-fixable:** no.

---

## Generic.Commenting.DocComment

Checks a doc comment's layout. Jumbo includes nine codes, each by name, so codes added upstream stay out:

- `ShortNotCapital` and `LongNotCapital`: the summary and the description start with a capital letter. Code at the
  start can go in backticks.
- `SpacingAfter`, `SpacingBetween`, `SpacingBeforeTags` and `SpacingAfterTagGroup`: one blank line between the
  summary, the description and the tags, and none after the last line.
- `ParamNotFirst`, `ParamGroup` and `TagsNotGrouped`: the `@param` tags first, together, and the other tags grouped.

A one-line doc comment isn't checked, a doc comment needn't start with a summary (an inline `@var`), and an empty one
is `SlevomatCodingStandard.Commenting.EmptyComment`'s.

**Auto-fixable:** partly. Only the spacing codes are fixed.

---

## Generic.Files.ExecutableFile

Disallows a PHP file with its executable permission set. A script meant to be run can be excluded in a project's
ruleset.

**Auto-fixable:** no.

---

## Generic.Metrics.NestingLevel

A warning for a function with blocks nested more than 5 deep, and an error for more than 10.

**Auto-fixable:** no.

---

## Generic.PHP.DeprecatedFunctions

Disallows a call to a native function that PHP marks deprecated, such as `strftime()`. The list comes from the PHP
that runs phpcs, so it grows with each PHP version.

**Auto-fixable:** no.

---

## Generic.PHP.DisallowRequestSuperglobal

Disallows `$_REQUEST`. Which of `$_GET`, `$_POST` and `$_COOKIE` it holds, and which wins, depends on the
`request_order` and `variables_order` ini settings.

**Auto-fixable:** no.

---

## Generic.PHP.DiscourageGoto

A warning for `goto`, and for its labels.

**Auto-fixable:** no.

---

## Generic.PHP.ForbiddenFunctions

Disallows `extract()`, which creates variables from an array's keys, so a reader can't tell where they come from.
Jumbo replaces the sniff's default list (`sizeof()` and `delete()`), as `PhpCollective.PHP.RemoveFunctionAlias`
covers aliases.

**Auto-fixable:** no.

---

## Generic.VersionControl.GitMergeConflict

Disallows a merge conflict marker. In code a marker is a parse error, but in a comment, heredoc, nowdoc or inline HTML
PHP accepts it.

**Auto-fixable:** no.

---

## Generic.WhiteSpace.ArbitraryParenthesesSpacing

Disallows a space just inside grouping parentheses, as in `( $a + $b ) * 2`. A line break inside them is allowed
(`ignoreNewlines`), as the layout of a split expression is left to
[`Jumbo.Formatting.ExpressionFormat`](Jumbo_Custom_Sniffs.md#jumboformattingexpressionformat) and
`PERCS31.Operators.OperatorPlacement`.

**Auto-fixable:** yes.

---

## Generic.WhiteSpace.HereNowdocIdentifierSpacing

Disallows a space between `<<<` and a heredoc's or nowdoc's identifier, as in `<<< 'EOT'`.

**Auto-fixable:** yes.

---

## Modernize.FunctionCalls.Dirname

Requires `__DIR__`, not `dirname(__FILE__)`, and the `levels` argument, as in `dirname(__DIR__, 2)`, not nested
`dirname()` calls.

**Auto-fixable:** yes.

---

## PhpCollective.Commenting.DocBlockReturnSelf

Requires `@return $this`, not `@return self` or the class's name, where every `return` in the method is
`return $this;`. Jumbo includes `SelfVsThis` and `ClassVsThis` only, not `InvalidChainable`, which can't tell when a
called method returns `$this`.

**Auto-fixable:** yes.

---

## PhpCollective.Commenting.DocBlockReturnTag

Disallows a `@return` description that starts with a variable, usually a copied `@param` line.

**Auto-fixable:** no.

---

## PhpCollective.Commenting.DocBlockStructure

Requires `/**` and `*/`, not `/***` or `**/`, and moves text on the `/**` line of a multi-line doc comment to the
next line. A one-line doc comment is left alone.

**Auto-fixable:** yes.

---

## PhpCollective.Commenting.DocBlockTagIterable

Requires one space after each comma in a generic doc type, and none inside the brackets, as in `array<string, int>`.

**Auto-fixable:** yes.

---

## PhpCollective.Commenting.InlineDocBlock

Requires an inline `@var` in a doc comment, `/** @var int $a */`, not `/* @var int $a */`, which tools ignore, and
its type before the variable, not `@var $a int`.

**Auto-fixable:** yes.

---

## PhpCollective.ControlStructures.UnneededElse

Disallows an `else` or `elseif` after branches that all exit early, with `return`, `throw`, `continue` or `break`.
The fix removes the `else` and moves its body out.

**Auto-fixable:** yes.

---

## PhpCollective.PHP.NoIsNull

Requires `$x === null`, not `is_null($x)`.

**Auto-fixable:** yes.

---

## PhpCollective.PHP.NotEqual

Requires `!=`, not `<>`.

**Auto-fixable:** yes.

---

## PhpCollective.PHP.PreferCastOverFunction

Requires a cast, not `strval()`, `intval()`, `floatval()` or `boolval()`, as in `(int) $a`. A call with a base
argument, or used as a callable, is left alone.

**Auto-fixable:** yes.

---

## PhpCollective.PHP.RemoveFunctionAlias

Disallows a function alias, such as `sizeof()`, `join()` or `is_integer()`, and replaces it with the main function:
`count()`, `implode()` or `is_int()`.

**Auto-fixable:** yes.

---

## PhpCollective.Testing.AssertPrimitives

Requires `assertNull()`, `assertTrue()` or `assertFalse()`, not `assertSame()` with `null`, `true` or `false`. Only
checks `$this->assert...()` calls in files named `*Test.php`.

**Auto-fixable:** yes.

---

## PhpCollective.WhiteSpace.DocBlockSpacing

Disallows a blank line between a doc comment and its class, interface, trait or function.

**Auto-fixable:** yes.

---

## PhpCollective.WhiteSpace.EmptyLines

Disallows more than one blank line in a row, anywhere in PHP code. A blank line inside a heredoc, a nowdoc or a block
comment is part of that string or comment, so it's left alone, as is HTML outside the PHP tags. PER Coding Style 3.1,
section 2.3, allows blank lines wherever they aren't forbidden, so PERCS31 allows any number.

**Auto-fixable:** yes.

---

## SlevomatCodingStandard.Arrays.ArrayAccess

Disallows whitespace between array access operators.

**Auto-fixable:** partly. Some of its codes can't be fixed.

---

## SlevomatCodingStandard.Arrays.DisallowImplicitArrayCreation

Disallows implicit array creation.

**Auto-fixable:** no.

---

## SlevomatCodingStandard.Arrays.DisallowPartiallyKeyed

Requires arrays to have keys for all or none of the values.

**Auto-fixable:** no.

---

## SlevomatCodingStandard.Attributes.AttributesOrder

Requires attributes to be ordered alphabetically.

**Auto-fixable:** yes.

---

## SlevomatCodingStandard.Classes.ClassLength

A class, interface, trait or enum of no more than 1000 lines of code (`maxLinesLength`), not counting comments and blank
lines.

**Auto-fixable:** no.

---

## SlevomatCodingStandard.Classes.ClassMemberSpacing

Enforces spacing between class members.

**Auto-fixable:** partly. Some of its codes can't be fixed.

---

## SlevomatCodingStandard.Classes.ConstantSpacing

Enforces the blank lines after a constant. Used through [`Jumbo.Classes.ConstantSpacing`](Jumbo_Custom_Sniffs.md#jumboclassesconstantspacing-enumcasespacing-and-propertyspacing), which skips a constant on the
file's last line, as the parent crashes on one.

**Auto-fixable:** yes.

---

## SlevomatCodingStandard.Classes.DisallowStringExpressionPropertyFetch

Disallows string expressions for property access.

**Auto-fixable:** yes.

---

## SlevomatCodingStandard.Classes.EmptyLinesAroundClassBraces

Enforces the blank lines inside a class's braces. Used through [`Jumbo.Classes.EmptyLinesAroundClassBraces`](Jumbo_Custom_Sniffs.md#jumboclassesemptylinesaroundclassbraces), which allows
an empty body as `{}`.

**Auto-fixable:** yes.

---

## SlevomatCodingStandard.Classes.EnumCaseSpacing

Enforces the blank lines after an enum case. Used through [`Jumbo.Classes.EnumCaseSpacing`](Jumbo_Custom_Sniffs.md#jumboclassesconstantspacing-enumcasespacing-and-propertyspacing), which skips a case on the
file's last line, as the parent crashes on one.

**Auto-fixable:** yes.

---

## SlevomatCodingStandard.Classes.MethodSpacing

Enforces spacing between methods.

**Auto-fixable:** yes.

---

## SlevomatCodingStandard.Classes.ModernClassNameReference

Requires `::class` instead of `get_class()`, `get_parent_class()` and `get_called_class()`.

**Auto-fixable:** yes.

---

## SlevomatCodingStandard.Classes.PropertySpacing

Enforces the blank lines after a property. Used through [`Jumbo.Classes.PropertySpacing`](Jumbo_Custom_Sniffs.md#jumboclassesconstantspacing-enumcasespacing-and-propertyspacing), which skips a property on the
file's last line, as the parent crashes on one.

**Auto-fixable:** yes.

---

## SlevomatCodingStandard.Classes.ReadonlyClass

Requires `readonly class` when every property of a final class is readonly, and reports `readonly` on a property of a
readonly class, where it's redundant. A class that isn't final is skipped, as a readonly class makes every subclass
readonly too.

**Auto-fixable:** yes.

---

## SlevomatCodingStandard.Classes.RequireConstructorPropertyPromotion

Requires a promoted constructor parameter where the constructor only assigns a parameter to a property of the same name
and type. A property with hooks, an attribute or a doc comment that says more than its type is skipped.

**Auto-fixable:** yes.

---

## SlevomatCodingStandard.Classes.RequireMultiLineMethodSignature

Requires multi-line format for long method signatures.

**Auto-fixable:** yes.

---

## SlevomatCodingStandard.Classes.RequireSelfReference

Requires `self::` instead of `ClassName::` for self-references.

**Auto-fixable:** yes.

---

## SlevomatCodingStandard.Classes.RequireSingleLineMethodSignature

Requires single-line format for short method signatures. Used through [`Jumbo.Classes.RequireSingleLineMethodSignature`](Jumbo_Custom_Sniffs.md#jumboclassesrequiresinglelinemethodsignature),
which skips a method with a hook on a promoted parameter.

**Auto-fixable:** yes.

---

## SlevomatCodingStandard.Classes.TraitUseOrder

Requires a class's trait `use` statements in alphabetical order, ignoring case.

**Auto-fixable:** yes.

---

## SlevomatCodingStandard.Commenting.DeprecatedAnnotationDeclaration

Enforces the `@deprecated` annotation format.

**Auto-fixable:** no.

---

## SlevomatCodingStandard.Commenting.EmptyComment

Disallows empty comments.

**Auto-fixable:** yes.

---

## SlevomatCodingStandard.Commenting.RequireOneDocComment

Disallows a second doc comment before a declaration. Merge it into the first, at the end of the tags.

**Auto-fixable:** no.

---

## SlevomatCodingStandard.Commenting.RequireOneLineDocComment

Requires a doc comment with one tag and no description on one line, as in `/** @var list<int> */`, for every
declaration and inline `@var`.

**Auto-fixable:** yes.

---

## SlevomatCodingStandard.Commenting.UselessInheritDocComment

Disallows useless `@inheritDoc` comments.

**Auto-fixable:** yes.

---

## SlevomatCodingStandard.ControlStructures.DisallowContinueWithoutIntegerOperandInSwitch

Disallows `continue` without an operand in `switch`.

**Auto-fixable:** yes.

---

## SlevomatCodingStandard.ControlStructures.LanguageConstructWithParentheses

Disallows parentheses around a language construct's argument. Used through
[`Jumbo.ControlStructures.LanguageConstructWithParentheses`](Jumbo_Custom_Sniffs.md#jumbocontrolstructureslanguageconstructwithparentheses), which skips `exit` and `die`.

**Auto-fixable:** yes.

---

## SlevomatCodingStandard.ControlStructures.RequireMultiLineTernaryOperator

Requires multi-line format for long ternary operators.

**Auto-fixable:** yes.

---

## SlevomatCodingStandard.ControlStructures.RequireNullCoalesceEqualOperator

Requires `??=` when possible.

**Auto-fixable:** yes.

---

## SlevomatCodingStandard.ControlStructures.RequireNullCoalesceOperator

Requires `??` when possible.

**Auto-fixable:** yes.

---

## SlevomatCodingStandard.ControlStructures.RequireNullSafeObjectOperator

Requires `?->` when possible.

**Auto-fixable:** yes.

---

## SlevomatCodingStandard.ControlStructures.RequireShortTernaryOperator

Requires the short ternary `?:` when possible. Used through [`Jumbo.ControlStructures.RequireShortTernaryOperator`](Jumbo_Custom_Sniffs.md#jumbocontrolstructuresrequireshortternaryoperator), which
skips a nullable type in a `set` hook's parameter.

**Auto-fixable:** yes.

---

## SlevomatCodingStandard.ControlStructures.RequireTernaryOperator

Requires ternary operator when possible.

**Auto-fixable:** partly. Some of its codes can't be fixed.

---

## SlevomatCodingStandard.ControlStructures.UselessIfConditionWithReturn

Disallows useless if conditions returning true or false.

**Auto-fixable:** partly. Some of its codes can't be fixed.

---

## SlevomatCodingStandard.ControlStructures.UselessTernaryOperator

Disallows a ternary that only gives `true` or `false`, such as `$a > $b ? true : false`. It's fixed only where the
condition is certainly boolean.

**Auto-fixable:** partly. Only where the condition is certainly boolean.

---

## SlevomatCodingStandard.Exceptions.DeadCatch

Disallows unreachable catch blocks, e.g. a catch after `catch (Throwable)`.

**Auto-fixable:** no.

---

## SlevomatCodingStandard.Exceptions.RequireNonCapturingCatch

Requires catch without variable when exception is not used.

**Auto-fixable:** yes.

---

## SlevomatCodingStandard.Functions.FunctionLength

Enforces maximum function length of 100 lines.

**Auto-fixable:** no.

---

## SlevomatCodingStandard.Functions.RequireArrowFunction

Requires an arrow function for a closure whose body is only a `return`, as in `fn($x) => $x * 2`. A closure that
captures a variable by reference is left alone.

**Auto-fixable:** yes.

---

## SlevomatCodingStandard.Functions.RequireMultiLineCall

Requires multi-line format for long function calls.

**Auto-fixable:** yes.

---

## SlevomatCodingStandard.Functions.RequireSingleLineCall

Requires a call that fits on one line to be on one line. Used through [`Jumbo.Functions.RequireSingleLineCall`](Jumbo_Custom_Sniffs.md#jumbofunctionsrequiresinglelinecall), which
skips a call with an anonymous class or `match` argument.

**Auto-fixable:** yes.

---

## SlevomatCodingStandard.Functions.StaticClosure

Requires the `static` keyword for closures that don't use `$this`.

**Auto-fixable:** yes.

---

## SlevomatCodingStandard.Functions.StrictCall

Requires the strict argument in `in_array()`, `array_search()`, `array_keys()` and `base64_decode()`.

**Auto-fixable:** no.

---

## SlevomatCodingStandard.Functions.UselessParameterDefaultValue

Disallows useless parameter default values.

**Auto-fixable:** yes.

---

## SlevomatCodingStandard.Namespaces.AlphabeticallySortedUses

Requires use statements to be alphabetically sorted.

**Auto-fixable:** partly. Some of its codes can't be fixed.

---

## SlevomatCodingStandard.Namespaces.DisallowGroupUse

Disallows compound namespaces.

**Auto-fixable:** no.

---

## SlevomatCodingStandard.Namespaces.MultipleUsesPerLine

Disallows multiple use statements on one line.

**Auto-fixable:** no.

---

## SlevomatCodingStandard.Namespaces.NamespaceDeclaration

Enforces proper namespace declaration format.

**Auto-fixable:** partly. Some of its codes can't be fixed.

---

## SlevomatCodingStandard.Namespaces.ReferenceUsedNamesOnly

Requires use statements for all referenced names.

**Auto-fixable:** partly. Some of its codes can't be fixed.

---

## SlevomatCodingStandard.Namespaces.RequireOneNamespaceInFile

Requires exactly one namespace per file.

**Auto-fixable:** no.

---

## SlevomatCodingStandard.Namespaces.UnusedUses

Disallows unused use statements. Used through [`Jumbo.Namespaces.UnusedUses`](Jumbo_Custom_Sniffs.md#jumbonamespacesunuseduses), which skips a file with an upper-case
`function` or `const` keyword in a use statement.

**Auto-fixable:** yes.

---

## SlevomatCodingStandard.Namespaces.UseFromSameNamespace

Disallows use statements from the same namespace.

**Auto-fixable:** yes.

---

## SlevomatCodingStandard.Namespaces.UselessAlias

Disallows useless use statement aliases.

**Auto-fixable:** yes.

---

## SlevomatCodingStandard.Operators.RequireCombinedAssignmentOperator

Requires combined assignment operators (`+=`, `-=`, etc.).

**Auto-fixable:** partly. Some of its codes can't be fixed.

---

## SlevomatCodingStandard.Operators.RequireOnlyStandaloneIncrementAndDecrementOperators

Requires `++` and `--` to be used standalone.

**Auto-fixable:** no.

---

## SlevomatCodingStandard.PHP.DisallowDirectMagicInvokeCall

Disallows direct `__invoke()` calls.

**Auto-fixable:** yes.

---

## SlevomatCodingStandard.PHP.RequireExplicitAssertion

Requires an `assert()` after an assignment, not an inline `@var` before it, as `assert()` is checked whenever
assertions are on. A type that `assert()` can't express, such as `list<int>`, keeps its `@var`.

**Auto-fixable:** yes.

---

## SlevomatCodingStandard.PHP.ShortList

Requires short list syntax `[]`.

**Auto-fixable:** yes.

---

## SlevomatCodingStandard.PHP.UselessSemicolon

Disallows useless semicolons.

**Auto-fixable:** yes.

---

## SlevomatCodingStandard.Strings.DisallowVariableParsing

Disallows variable parsing in strings.

**Auto-fixable:** no.

---

## SlevomatCodingStandard.TypeHints.ClassConstantTypeHint

Requires class constant type hints. Its `UselessVarAnnotation` code removes a `@var` that only repeats the native
type, with no description.

**Auto-fixable:** partly. Some of its codes can't be fixed.

---

## SlevomatCodingStandard.TypeHints.DisallowArrayTypeHintSyntax

Requires generic syntax for an array type in a doc comment, as in `array<int>`, not `int[]`.

**Auto-fixable:** yes. The fix writes `array<T>`. Narrow it to `list<T>` by hand where the keys are sequential.

---

## SlevomatCodingStandard.TypeHints.NullableTypeForNullDefaultValue

Requires nullable type for parameters with null default.

**Auto-fixable:** yes.

---

## SlevomatCodingStandard.TypeHints.ParameterTypeHint

Requires parameter type hints. Used through [`Jumbo.TypeHints.ParameterTypeHint`](Jumbo_Custom_Sniffs.md#jumbotypehintsparametertypehint), which skips a function with a hook on a
promoted parameter. Its `UselessAnnotation` code removes a `@param` that only repeats the native type, with no
description; one with a more specific type, such as `list<int>` for `array`, is kept.

**Auto-fixable:** partly. Some of its codes can't be fixed.

---

## SlevomatCodingStandard.TypeHints.ParameterTypeHintSpacing

Enforces spacing around parameter type hints. Used through [`Jumbo.TypeHints.ParameterTypeHintSpacing`](Jumbo_Custom_Sniffs.md#jumbotypehintsparametertypehintspacing), which skips a
function with a hook on a promoted parameter.

**Auto-fixable:** yes.

---

## SlevomatCodingStandard.TypeHints.PropertyTypeHint

Requires property type hints. Its `UselessAnnotation` code removes a `@var` that only repeats the native type, with
no description.

**Auto-fixable:** partly. Some of its codes can't be fixed.

---

## SlevomatCodingStandard.TypeHints.ReturnTypeHint

Requires return type hints. Its `UselessAnnotation` code removes a `@return` that only repeats the native type, with
no description.

**Auto-fixable:** partly. Some of its codes can't be fixed.

---

## SlevomatCodingStandard.TypeHints.ReturnTypeHintSpacing

Enforces spacing around return type hints.

**Auto-fixable:** yes.

---

## SlevomatCodingStandard.Variables.DisallowVariableVariable

Disallows variable variables (`$$var`).

**Auto-fixable:** no.

---

## SlevomatCodingStandard.Variables.DuplicateAssignmentToVariable

Disallows duplicate assignments to the same variable.

**Auto-fixable:** no.

---

## SlevomatCodingStandard.Whitespaces.DuplicateSpaces

Disallows two or more spaces in a row in code, other than indentation. It's a safety net for the sniffs that check each
construct's spacing, so a run of spaces can be reported twice; both fixes agree. Aligned text in a comment or a doc
comment is allowed (`ignoreSpacesInComment`, `ignoreSpacesInAnnotation`).

**Auto-fixable:** yes.

---

## Squiz.Arrays.ArrayDeclaration

The check PERCS31 leaves out: no mix of keyed and unkeyed values. Which line a comma goes on is left to
[`Jumbo.Arrays.ElementComma`](Jumbo_Custom_Sniffs.md#jumboarrayselementcomma). The spacing around `=>` is left to [`PERCS31.WhiteSpace.ArrowSpacing`](PERCS31_Custom_Sniffs.md#percs31whitespacearrowspacing), as Squiz only checks
it in a single-line array. Its alignment checks and `SingleLineNotAllowed` stay excluded, so keys, values and arrows are
not aligned, and a single-line array with several values is allowed. `Jumbo.Arrays.ArrayDeclaration` requires
single-line where it fits.

**Auto-fixable:** no.

---

## Squiz.Classes.ClassFileName

Requires a file to be named after the class, interface, trait or enum it declares, so the autoloader can find it. It
checks the file name only, not the directory or the namespace.

**Auto-fixable:** no.

---

## Squiz.Commenting.DocCommentAlignment

Requires a docblock's stars to line up under the first `*` of `/**`, with one space after each star that has text after
it. Only a docblock before a declaration, or at the top of a file, is checked, so an inline `/** @var */` isn't.

**Auto-fixable:** yes.

---

## Squiz.Commenting.EmptyCatchComment

Requires a comment in an empty `catch` block, saying why the exception is ignored.

**Auto-fixable:** no.

---

## Squiz.Commenting.InlineComment

Requires one space after `//`, as in `// Text`. Only its `NoSpaceBefore`, `SpacingBefore` and `TabBefore` codes are
used, so `//Text`, `// Text` and `///` are reported. A `#` comment isn't checked.

**Auto-fixable:** yes.

---

## Squiz.NamingConventions.ValidFunctionName

Requires a global function's name in camelCase, without a leading `__`, which is for magic methods. Jumbo includes
the `NotCamelCaps` and `DoubleUnderscore` codes only, not the method codes, which require an underscore before a
private method's name. The ASCII-only case check also rejects a name with non-ASCII or invisible characters.

**Auto-fixable:** no.

---

## Squiz.NamingConventions.ValidVariableName

Enforces `$camelCase` for variables, parameters, and properties. Its checks for an underscore on properties are
excluded, as one requires it on private properties. See [Variable and Property Names](VariableAndPropertyNames.md).

**Auto-fixable:** no.

---

## Squiz.Operators.IncrementDecrementUsage

Requires an increment or decrement operator in place of `$i += 1`, `$i -= 1` or `$i = $i + 1`, as `Found`. Its message
suggests `++$i`, but `$i++` is fine too, as nothing checks which is used.

Jumbo excludes the `NotAllowed` and `NoBrackets` codes, as
`SlevomatCodingStandard.Operators.RequireOnlyStandaloneIncrementAndDecrementOperators` reports the same lines.

**Auto-fixable:** no.

---

## Squiz.Operators.ValidLogicalOperators

Requires `&&` and `||` instead of `and` and `or`, which bind more loosely than `=`, so `$ok = $a and $b;` assigns `$a`.

**Auto-fixable:** no, as the fix would change precedence.

---

## Squiz.PHP.DisallowMultipleAssignments

Requires an assignment to be the first thing on its line, so `$a = $b = 1` is reported. An assignment in a `for`
header, a `while` condition or a default value is allowed.

Jumbo excludes the `FoundInControlStructure` code, for an assignment in an `if`, `elseif`, `switch` or `match`
condition, as `Generic.CodeAnalysis.AssignmentInCondition` warns about it instead.

**Auto-fixable:** no.

---

## Squiz.PHP.Eval

A warning for `eval()`.

**Auto-fixable:** no.

---

## Squiz.PHP.GlobalKeyword

Disallows the `global` statement.

**Auto-fixable:** no.

---

## Squiz.PHP.InnerFunctions

Disallows a named function declared inside a function. It becomes global when the outer one runs, so a second call
fails with "cannot redeclare". A closure is fine.

**Auto-fixable:** no.

---

## Squiz.PHP.LowercasePHPFunctions

Requires a native function's name in lower case, in calls and `use function` imports, as in `strlen()`, not
`StrLen()`.

**Auto-fixable:** yes.

---

## Squiz.Scope.StaticThisUsage

Disallows `$this` in a static method, which PHP only rejects when the line runs.

**Auto-fixable:** no.

---

## Squiz.Strings.DoubleQuoteUsage.NotRequired

Ensures strings use single quotes unless double quotes are necessary.

**Auto-fixable:** yes.

---

## Squiz.WhiteSpace.FunctionOpeningBraceSpace.SpacingAfter

No blank line straight after the opening brace of a function, method or closure. PER only rules one out before the
closing brace, so PERCS31 allows it. See [A Blank Line at the Start of a Body](PER-CS_3.1_Judgement_Calls.md#a-blank-line-at-the-start-of-a-body).

**Auto-fixable:** yes.

---

## Squiz.WhiteSpace.OperatorSpacing

Requires exactly one space around operators, including before `=`, so assignments are not aligned. Used through
[`PERCS31.Operators.OperatorSpacing`](PERCS31_Custom_Sniffs.md#percs31operatorsoperatorspacing), which extends it to `&&`, `||`, `and`, `or`, `xor`, `.` and `|>`. PERCS31 excludes
its codes for more than one space, and Jumbo turns them back on. `=>` is covered by [`PERCS31.WhiteSpace.ArrowSpacing`](PERCS31_Custom_Sniffs.md#percs31whitespacearrowspacing).

**Auto-fixable:** yes.

---

## Squiz.WhiteSpace.SemicolonSpacing

Disallows white space before a semicolon, including a line break. In a `for` header,
`Squiz.ControlStructures.ForLoopDeclaration` reports the same space, and both fixes agree.

**Auto-fixable:** yes.

---

## Universal.Arrays.DuplicateArrayKey

Disallows a key repeated in an array literal, where PHP silently keeps the last value.

**Auto-fixable:** no.

---

## Universal.CodeAnalysis.ConstructorDestructorReturn

A warning for returning a value from a constructor or destructor, which PHP allows but ignores when called by
`new`. Jumbo includes the `ReturnValueFound` code only.

**Auto-fixable:** no.

---

## Universal.CodeAnalysis.ForeachUniqueAssignment

Disallows the same variable as a `foreach`'s key and value, as in `foreach ($a as $k => $k)`.

**Auto-fixable:** yes.

---

## Universal.CodeAnalysis.NoDoubleNegative

Disallows a double negative: `!!$x` is fixed to `(bool) $x`, and `!!!$x` to `!$x`. Not fixed beside `instanceof`.

**Auto-fixable:** mostly.

---

## Universal.CodeAnalysis.NoEchoSprintf

Requires `printf()`, not `echo sprintf()`, and `vprintf()`, not `echo vsprintf()`.

**Auto-fixable:** yes.

---

## Universal.CodeAnalysis.StaticInFinalClass

Requires `self` instead of `static` in a final class, where they mean the same: in `self::`, `new self` and `instanceof
self`. Not for a return type: Jumbo excludes the `ReturnType` code, as on PHP 8.4 `self` can't replace a `static` return
type that a parent declares.

**Auto-fixable:** yes.

---

## Universal.Constants.UppercaseMagicConstants

Requires a magic constant in upper case, as in `__DIR__`, not `__dir__`. PHP ignores their case. Section 2.5's rule that
keywords are in lower case isn't read as covering them, so PERCS31 doesn't check their case.

**Auto-fixable:** yes.

---

## Universal.Files.SeparateFunctionsFromOO

Disallows functions declared in a file with a class, interface, trait or enum. A file of functions can be loaded by
Composer's `files` autoloading.

**Auto-fixable:** no.

---

## Universal.FunctionDeclarations.NoLongClosures

A warning for a closure over 10 lines of code, and an error over 20. Blank lines and comments aren't counted. A
longer one is clearer as a named function or method.

**Auto-fixable:** no.

---

## Universal.OOStructures.AlphabeticExtendsImplements

Requires the interfaces in an `implements` or interface `extends` list in alphabetical order, by short name.

**Auto-fixable:** yes.

---

## Universal.PHP.NoFQNTrueFalseNull

Disallows a leading backslash on `true`, `false` and `null`.

**Auto-fixable:** yes.

---

## Universal.PHP.OneStatementInShortEchoTag

Requires one statement in a short echo tag. A tag with more becomes `<?php echo`.

**Auto-fixable:** yes.

---

## Zend.Files.ClosingTag

Disallows a closing tag at the end of a file, including one that mixes PHP and HTML. PERCS31's `PSR2.Files.ClosingTag`
only checks PHP-only files. A file that ends in HTML has no closing tag at its end, so it passes.

**Auto-fixable:** yes.
