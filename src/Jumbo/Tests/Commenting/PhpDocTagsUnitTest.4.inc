<?php

class TagLayout
{
    /**
     * Allowed: an @see as the first tag, and @todo, @fixme and @see tags anywhere.
     *
     * @see https://www.php.net/manual/en/function.uasort.php
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
     * Allowed: an inline @see.
     *
     * Like {@see https://www.php.net/manual/en/function.uasort.php uasort()}, but returns a copy.
     *
     * @param int $a The value.
     */
    public function two(int $a): void
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
    public function three(int $a, int $b): int
    {
    }

    /**
     * Reported: a blank line between @param tags.
     *
     * @param int $a The value.
     *
     * @param int $b The other value.
     */
    public function four(int $a, int $b): void
    {
    }
}
