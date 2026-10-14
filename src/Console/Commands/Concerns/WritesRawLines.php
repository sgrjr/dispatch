<?php

namespace Sgrjr\Dispatch\Console\Commands\Concerns;

use Symfony\Component\Console\Output\OutputInterface;

/**
 * Machine output (--json) is written RAW, past Symfony's console formatter.
 *
 * Through `line()` the formatter reads `<…>` as a style tag and `\<` as an
 * escaped one. A task whose text holds `C:\Users\<account>` json_encodes to
 * `C:\\Users\\<account>`, and the formatter eats one backslash of the `\\<`,
 * leaving `\<` in the output: invalid JSON, and every consumer that parses
 * it fails (TASK-1754). Raw output also keeps `<info>`-like text inside task
 * content from vanishing.
 */
trait WritesRawLines
{
    protected function rawLine(string $text): void
    {
        $this->output->writeln($text, OutputInterface::OUTPUT_RAW);
    }
}
