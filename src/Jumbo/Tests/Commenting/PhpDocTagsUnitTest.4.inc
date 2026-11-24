<?php

class TagLayout
{
    /**
     * Allowed: an @see after the description, and @todo and @see tags anywhere.
     * @see https://www.php.net/manual/en/function.uasort.php
     *
     * @todo Make it faster.
     * @param int $a The value.
     * @param int $b The other value.
     * @see self::two()
     * @return int The result.
     * @fixme Sometimes wrong.
     * @throws Exception Sometimes.
     * @throws Error Other times.
     */
    public function one(int $a, int $b): int
    {
    }

    /**
     * Allowed: an inline @see, and an @see in its own paragraph.
     *
     * Like {@see https://www.php.net/manual/en/function.uasort.php uasort()}, but returns a copy.
     *
     * @see https://www.php.net/manual/en/function.usort.php
     *
     * @param int $a The value.
     */
    public function two(int $a): void
    {
    }

    /**
     * Allowed: an @see that starts the tags.
     *
     * @see https://www.php.net/manual/en/function.usort.php
     * @var int
     */
    public int $three = 1;

    /**
     * Reported: no blank line before the tags.
     * @param int $a The value.
     */
    public function four(int $a): void
    {
    }

    /**
     * Reported: @param tags out of order and apart, @return before a @param, and @throws before @return.
     *
     * @param int $b The other value.
     * @throws Exception Sometimes.
     * @return int The result.
     * @param int $a The value.
     */
    public function five(int $a, int $b): int
    {
    }

    /**
     * Reported: no blank line between an @see in the description and the tags, and a blank line between @param tags.
     * @see https://www.php.net/manual/en/function.uasort.php
     * @param int $a The value.
     *
     * @param int $b The other value.
     */
    public function six(int $a, int $b): void
    {
    }
}
