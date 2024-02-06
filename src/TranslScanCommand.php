<?php

namespace PlayeRom\TranslationsScan;

use Illuminate\Console\Command;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use Symfony\Component\Console\Helper\ProgressBar;

/**
 * Command class
 */
class TranslScanCommand extends Command
{
    const START_UNDERSCORE_EMPTY = '__()';
    const START_UNDERSCORE_FUNC = '__(';
    const START_LANG_FUNC = '@lang(';

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'lang:scan {language=pl : The language code as output file}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Scan Laravel project for obtain all texts which will need translations.';

    private Filesystem $fileSystem;

    private ?ProgressBar $progressBar;

    /**
     * Create a new command instance.
     */
    public function __construct(Filesystem $fileSystem)
    {
        parent::__construct();

        $this->fileSystem = $fileSystem;
    }

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $langaugeCode = $this->argument('language');
        $outputFile = base_path("/lang/$langaugeCode.json");

        $resultsArray = array_unique(Arr::sort($this->parseFiles()));

        $currentTexts = $this->getCuttentTextsInOutpurFile($outputFile);

        $outputContent = $this->createOutput($currentTexts, $resultsArray);

        $this->fileSystem->replace($outputFile, $outputContent);

        $this->progressBar->finish();

        $this->info('');
        $this->info("The output file has been created: $outputFile");

        return Command::SUCCESS;
    }

    /**
     * Parse all PHP file in Laravel project
     *
     * @return array Array with texts found.
     */
    private function parseFiles(): array
    {
        $resultsArray = [];

        $files = $this->fileSystem->allFiles(base_path());

        $this->progressBar = $this->output->createProgressBar(count($files));
        $this->progressBar->start();

        foreach ($files as $file) {
            if ($this->fileSystem->extension($file) === 'php') {
                $this->parseSinglePhpFile($file, $resultsArray);
            }

            $this->progressBar->advance();
        }

        return $resultsArray;
    }

    /**
     * Parse single PHP file.
     */
    private function parseSinglePhpFile(string $file, array &$resultsArray): void
    {
        $content = $this->fileSystem->get($file);

        $contentLength = Str::length($content);
        $cursor = 0;
        while ($cursor < $contentLength) {
            $startIgnore = Str::position($content, self::START_UNDERSCORE_EMPTY, $cursor);
            $start__     = Str::position($content, self::START_UNDERSCORE_FUNC, $cursor);
            $startLang   = Str::position($content, self::START_LANG_FUNC, $cursor);

            if ($startIgnore !== false && $startIgnore === $start__) {
                // empty __(), skip it and continue
                $cursor += Str::length(self::START_UNDERSCORE_EMPTY);
                continue;
            }

            if ($start__ === false && $startLang === false) {
                break; // nothing found
            }

            $this->setIntMaxIfFalse($start__);
            $this->setIntMaxIfFalse($startLang);

            $start = min([$start__, $startLang]);
            $startMarkerLen = Str::length(
                $start === $start__
                    ? self::START_UNDERSCORE_FUNC
                    : self::START_LANG_FUNC
            );

            // find first text into quotation mark
            $end = false;
            $quoteMark = '';
            $text = $this->findTextBeetwenQuotations($content, $start + $startMarkerLen, $end, $quoteMark);
            if (empty($text)) {
                $cursor = ($start + $startMarkerLen);
                continue;
            }
            $resultsArray[] = $text;

            $cursorShift = $this->checkConcateString($end, $contentLength, $content, $quoteMark, $resultsArray);

            $cursor = $cursorShift;
        }
    }

    /**
     * Check that string is concatenate
     * Check which char is next after ending $quoteMark, whether '.' whether ')'
     * If it's '.' then we have continuation of the string!
     */
    private function checkConcateString(
        int $end,
        int $contentLength,
        string $content,
        string $quoteMark,
        array &$resultsArray
    ): int {
        $index = $end + 1;
        $cursorShift = $index;
        for (; $index < $contentLength; ++$index) {
            $ch = Str::substr($content, $index, 1);
            if ($ch === ')' || $ch === ',') {
                // we haven't concatenation
                break;
            }

            if ($ch === '.') {
                // we have concatenation of string, se search start and end of this string
                $end = PHP_INT_MAX;
                $text = $this->findTextBeetwenQuotations($content, $index, $end, $quoteMark);
                if (!empty($text)) {
                    $resultsArray[count($resultsArray) - 1] .= $text;
                }
                $index = $end;
                $cursorShift = $index;
            }
        }

        return $cursorShift;
    }

    /**
     * Set PHP_INT_MAX if given variable if false
     */
    private function setIntMaxIfFalse(int|bool &$var): void
    {
        if ($var === false) {
            $var = PHP_INT_MAX;
        }
    }

    /**
     * Find text beetwen quotations
     *
     * @return string  Empty string if nothing found.
     */
    private function findTextBeetwenQuotations(
        string $content,
        int $start,
        int|bool &$outEnd,
        string &$outQuoteMark
    ): string {
        $startNoQuote = Str::position($content, ')', $start);
        $startQuote1  = Str::position($content, '\'', $start);
        $startQuote2  = Str::position($content, '"', $start);

        if ($startQuote1 === false && $startQuote2 === false) {
            return '';
        }

        $this->setIntMaxIfFalse($startQuote1);
        $this->setIntMaxIfFalse($startQuote2);

        $startQuote = min([$startQuote1, $startQuote2]);
        if ($startNoQuote !== false && $startNoQuote < $startQuote) {
            // there is no string into lang function, e.g. we are in regular expression _(?!_)
            return '';
        }
        $outQuoteMark = Str::substr($content, $startQuote, 1);

        // search end string
        $outEnd = false;
        $tmp = $startQuote + 1;
        while (true) {
            $outEnd = Str::position($content, $outQuoteMark, $tmp);
            if ($outEnd === false || $outEnd <= 0) {
                break;
            }

            if (Str::substr($content, $outEnd - 1, 1) === "\\") {
                // check for \' in string, if it's \' continue searching the end of string
                $tmp = $outEnd + 1;
                continue;
            }

            break;
        }

        $text = Str::substr($content, $startQuote + 1, $outEnd - $startQuote - 1);
        return Str::replace("\\'", "'", $text);
    }

    /**
     * Read current values in output json file
     */
    private function getCuttentTextsInOutpurFile(string $outputFile): array
    {
        try {
            $content = $this->fileSystem->get($outputFile);
            $result = json_decode($content, true);
            if (is_array($result)) {
                return $result;
            }
            return [];
        } catch (\Illuminate\Contracts\Filesystem\FileNotFoundException $exc) {
            return [];
        }
    }

    /**
     * Create ouput json content
     */
    private function createOutput(array $currentTexts, array $resultsArray): string
    {
        $result = '{' . PHP_EOL;

        $comma = '';
        foreach ($currentTexts as $key => $value) {
            $key = $this->replaceInAlreadyJsonText($key);
            $value = $this->replaceInAlreadyJsonText($value);

            $result .= $this->getJsonLine($key, $value, $comma);

            if (($delKey = array_search($key, $resultsArray)) !== false) {
                // found text in $this->results, remove it for avoid duplication
                unset($resultsArray[$delKey]);
            }
        }

        // Write the remaining texts on the output
        foreach ($resultsArray as $text) {
            $result .= $this->getJsonLine($text, $text, $comma);
        }

        $result .= PHP_EOL . '}' . PHP_EOL;
        return $result;
    }

    /**
     * Replace some chars to another for json format
     */
    private function replaceInAlreadyJsonText(string $text): string
    {
        return Str::replace(['"', PHP_EOL], ['\\"', '\\n'], $text);
    }

    /**
     * Get json string key and value
     */
    private function getJsonLine(string $key, string $value, string &$comma): string
    {
        $result = '';
        if (!empty($comma)) {
            $result .= $comma . PHP_EOL;
        }
        $result .= '    "' . $key . '": "' . $value . '"';
        $comma = ',';

        return $result;
    }
}
