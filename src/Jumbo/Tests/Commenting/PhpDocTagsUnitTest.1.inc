<?php

namespace Foo;

use Some\Annotation\Group;
use Some\Annotation\Since;

// Correct tags, in a namespaced file.

/**
 * Summary with {@inheritDoc} and {@see Bar::baz()} inline.
 *
 * See @returns here, and {@foo} and { @ids }, aren't tags to check.
 *
 * @param int $a The number.
 * @return int The result.
 * @phpstan-param positive-int $a
 * @property-read int $b
 * @property-write int $c
 * @static
 * @Type("string")
 * @ORM\Column(type="string")
 * @Group
 * @Since 1.0
 */

/** @inheritDoc */

// Casing.

/**
 * @PARAM int $a
 * @Return int
 * @inheritdoc
 * @phpstan-Param int $a
 * @CodeCoverageIgnore
 */

/** @inheritdoc */

// Aliases, in any case.

/**
 * @returns int The result.
 * @Returns int
 * @inheritdocs
 * @inheritDocs
 * @INHERITDOCS
 * @type int
 * @const int
 * @link https://example.com
 */

// Inline tags: casing and aliases.

/**
 * Summary {@inheritdoc} and {@See Bar} and {@link https://example.com Example} and {@inheritDocs}.
 */

// Inline format.

/**
 * @{inheritDoc}
 * {{@inheritDoc}} b
 * { @see Bar  } c
 * {@ see Bar} d
 * Text @{see Bar} more.
 * {@see Bar}} e
 * {@see  Bar} f
 * @{inheritDoc test} g
 */

// Inline format, then casing.

/**
 * @{inheritdoc}
 * Text { @See Bar }.
 */

// An inline tag split over two lines isn't checked.

/**
 * Text { @see
 * Bar }.
 */
