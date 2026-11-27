<?php

// phpcs:set PERCS31.Formatting.ExpressionFormat reflowStringExpressions true
// phpcs:set PERCS31.Formatting.ExpressionFormat maxLineLength 40
// Valid: fits on its line, or only one line break, or a line written over lines that starts with a tab.
$short = "a\nb\nc";
$two = 'short
string';
$tab = 'first line long enough to not fit here
	tabbed
last';

// Invalid: two or more line breaks that do not fit: a heredoc, or a nowdoc for a single-quoted string, named
// JSON where it is certain, or TEXT2 where a line would end TEXT early; a trailing line break keeps an empty line.
$text = "First line\nSecond, \"quoted\"\nThird $name\n";
$json = "{\n    \"name\": \"Shaun\",\n    \"age\": 50\n}";
$doc = "Line\nTEXT is here\nand more text to be long";
$sql = 'SELECT name
    FROM users
    WHERE id = 1 AND \'x\' = 2';
$cat = 'First part of it' . "\n" . 'second part' . "\n" . 'third';
// phpcs:set PERCS31.Formatting.ExpressionFormat maxLineLength 120
// phpcs:set PERCS31.Formatting.ExpressionFormat reflowStringExpressions false
