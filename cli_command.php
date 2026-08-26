<?php
if (!defined('PHPWG_ROOT_PATH')) die('Hacking attempt!');

final class PwgCommand
{
  public const SUCCESS = 0; // the command ran fine
  public const ERROR = 1;   // the command ran and failed, or was aborted
  public const INVALID = 2; // the command never ran, the user typed something wrong

  private const GREEN = 32;
  private const RED = 31;
  private const YELLOW = 33;

  private static ?bool $decorated = null;
  private static bool $assume_yes = false;
  private static bool $verbose = false;
  private static bool $dry_run = false;

  private function __construct() {}

  /**
  * Make every confirm() answer yes without asking. Called by the engine when
  * --yes is passed, a command never needs to call it.
  */
  public static function assume_yes()
  {
    self::$assume_yes = true;
  }

  /**
  * Turn dry-run mode on. Called by the engine when --dry-run is passed, a
  * command never needs to call it.
  */
  public static function set_dry_run()
  {
    self::$dry_run = true;
  }

  /**
  * True when --dry-run was passed. A command that writes anything MUST honor
  * it: report what would happen, change nothing, return SUCCESS.
  */
  public static function is_dry_run(): bool
  {
    return self::$dry_run;
  }

  /**
  * Turn verbose mode on. Called by the engine when --verbose is passed, a
  * command never needs to call it.
  */
  public static function set_verbose()
  {
    self::$verbose = true;
  }

  /**
  * True when --verbose was passed: print your debug details (full errors,
  * timings...), keep the default output clean otherwise.
  */
  public static function is_verbose(): bool
  {
    return self::$verbose;
  }

  /**
  * Ask a free-form question and return the trimmed answer. On a closed STDIN
  * (cron, CI) it returns the empty string instead of blocking, so give every
  * question a sensible empty-answer behavior.
  */
  public static function prompt(string $message): string
  {
    self::output(STDOUT, $message.' ', PHP_EOL, '');

    $input = fgets(STDIN);

    // EOF (cron, closed stdin) answers the empty string, never blocks
    return $input === false
      ? ''
      : trim($input);
  }

  /**
  * Ask before doing something destructive. Defaults to no: --yes is the only
  * way to say yes non-interactively, so a cron without it aborts safely.
  */
  public static function confirm(string $question): bool
  {
    if (self::$assume_yes)
    {
      return true;
    }

    $answer = strtolower(self::prompt($question.' [y/N]'));

    return in_array($answer, ['y', 'yes']);
  }

  /**
  * Color a piece of text green, for building custom output like a report
  * line. Colors only show on a terminal, a piped or redirected output stays
  * plain, safe to use anywhere.
  */
  public static function green(string $text): string
  {
    return self::paint($text, self::GREEN);
  }

  /**
  * Color a piece of text yellow. Same terminal-only rule as green().
  */
  public static function yellow(string $text): string
  {
    return self::paint($text, self::YELLOW);
  }

  /**
  * Color a piece of text red. Same terminal-only rule as green().
  */
  public static function red(string $text): string
  {
    return self::paint($text, self::RED);
  }

  private static function paint(string $text, int $code): string
  {
    global $conf;

    if (self::$decorated === null)
    {
      self::$decorated = stream_isatty(STDOUT);
    }

    // if we don't out the result in file and allow color show color
    return self::$decorated && $conf['cli_allow_color'] 
      ? "\033[".$code."m".$text."\033[0m" 
      : $text;
  }

  /**
  * @param resource $stream
  * @param string|array $message
  */
  private static function output($stream, $message, string $line_start = PHP_EOL, string $line_end = PHP_EOL)
  {
    fwrite($stream, self::implode_recursive($line_start, (array) $message) . $line_end);
  }

  private static function implode_recursive(string $separator, array $array): string
  {
    $flat = [];
    foreach ($array as $a)
    {
      $flat[] = is_array($a) ? self::implode_recursive($separator, $a) : $a;
    }

    return implode($separator, $flat);
  }

  /**
  * Print on STDOUT, ending with a newline. An array prints one line per
  * entry, so a whole report can be built then written in one call.
  *
  * @param string|array $message
  */
  public static function writeln($message)
  {
    self::output(STDOUT, $message);
  }

  /**
  * Print on STDOUT without a newline, to build a line piece by piece.
  * An array prints its entries separated by a comma.
  *
  * @param string|array $message
  */
  public static function write($message)
  {
    self::output(STDOUT, $message, ', ', '');
  }

  /**
  * Print anything as pretty JSON on STDOUT. Prefer it when you want to print
  * readable data (like 1 user): writeln() flattens arrays into lines and
  * loses the keys, writeJson() keeps the structure and stays parseable.
  *
  * @param mixed $message
  */
  public static function writeJson($message)
  {
    fwrite(STDOUT, json_encode($message, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL);
  }

  /**
  * Print on STDERR, ending with a newline. Use it for anything that is not
  * the command's result, so "pwg x > file" keeps the file clean.
  *
  * @param string|array $message
  */
  public static function errln($message)
  {
    self::output(STDERR, $message);
  }

  /**
  * Print a mysql-style table on STDOUT. The keys of the first row are the
  * headers.
  *
  * The optional $headers replaces the displayed names (in column order), and
  * makes an empty $rows still show the header grid instead of nothing.
  */
  public static function table(array $rows, ?array $headers = null)
  {
    $columns = empty($rows) ? [] : array_keys((array) reset($rows));

    if (null === $headers)
    {
      if (empty($columns))
      {
        return; // no rows, no headers: nothing to show
      }
      $headers = $columns;
    }
    elseif (empty($columns))
    {
      $columns = array_keys($headers); // header-only grid for an empty result
    }

    $headers = array_values($headers);

    $widths = [];
    foreach ($headers as $i => $header)
    {
      $widths[$i] = self::visible_width((string) $header);
    }
    foreach ($rows as $row)
    {
      $row = (array) $row;
      foreach ($columns as $i => $key)
      {
        $widths[$i] = max($widths[$i] ?? 0, self::visible_width((string) ($row[$key] ?? '')));
      }
    }

    $separator = '+';
    foreach ($widths as $width)
    {
      $separator .= str_repeat('-', $width + 2).'+';
    }

    $lines = [$separator, self::table_row($headers, $widths), $separator];
    foreach ($rows as $row)
    {
      $row = (array) $row;

      $cells = [];
      foreach ($columns as $key)
      {
        $cells[] = $row[$key] ?? '';
      }

      $lines[] = self::table_row($cells, $widths);
    }
    $lines[] = $separator;

    self::writeln($lines);
  }

  private static function table_row(array $cells, array $widths): string
  {
    $line = '|';
    foreach ($widths as $i => $width)
    {
      $cell = (string) ($cells[$i] ?? '');
      $line .= ' '.$cell.str_repeat(' ', $width - self::visible_width($cell)).' |';
    }

    return $line;
  }

  // str_pad counts bytes, an accent would shift every column after it
  private static function visible_width(string $text): int
  {
    return function_exists('mb_strwidth') ? mb_strwidth($text, 'UTF-8') : strlen($text);
  }

  /**
  * Print a green "[OK]" message on STDOUT, for the final good news of a
  * command.
  */
  public static function success(string $message)
  {
    self::writeln(self::paint('[OK] ', self::GREEN). $message);
  }

  /**
  * Print a yellow "[WARNING]" message on STDERR: something odd but not
  * blocking, the command goes on.
  */
  public static function warning(string $message)
  {
    self::errln(self::paint('[WARNING] ', self::YELLOW). $message);
  }

  /**
  * Print a red "[ERROR]" message on STDERR. It only prints, the command
  * still has to return PwgCommand::ERROR itself.
  */
  public static function error(string $message)
  {
    self::errln(self::paint('[ERROR] ', self::RED). $message);
  }
}
