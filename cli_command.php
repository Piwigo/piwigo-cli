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
  private static string $format = 'table';

  // progress bar state, one bar at a time
  private const PROGRESS_BAR_WIDTH = 30;
  private static bool $progress_active = false;
  private static ?int $progress_total = null;
  private static int $progress_current = 0;
  private static string $progress_label = '';
  private static float $progress_started = 0.0;
  private static float $progress_drawn = 0.0;
  private static int $progress_milestone = -1;
  private static int $progress_width = 0;
  private static ?bool $progress_tty = null;

  // pagination state, filled by paginate() and read by pagination_footer()
  private static array $pagination = [];

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
  * Choose how table() and record() print. Called by the engine when --format is passed,
  * a command never needs it. Returns false when the name is not one we know.
  */
  public static function set_format(string $format): bool
  {
    if (!in_array($format, ['table', 'json']))
    {
      return false;
    }

    self::$format = $format;

    return true;
  }

  /**
  * The output format asked for: "table" by default, "json" with --format=json. Read it
  * when a command prints something table() and record() cannot express.
  */
  public static function format(): string
  {
    return self::$format;
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
    // a message printed during a progress bar gets its own line, the bar is redrawn below
    $bar_shown = self::$progress_active && self::progress_is_tty();
    if ($bar_shown)
    {
      self::progress_clear();
    }

    fwrite($stream, self::implode_recursive($line_start, (array) $message) . $line_end);

    if ($bar_shown && PHP_EOL === $line_end)
    {
      self::progress_draw(true);
    }
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
  * Print one record: a two-column field/value table, or the object itself with
  * --format=json. Use it for "show me this one thing", table() for a list of things.
  */
  public static function record(array $data)
  {
    if ('json' === self::$format)
    {
      self::writeJson($data);
      return;
    }

    $rows = [];
    foreach ($data as $field => $value)
    {
      $rows[] = [
        'field' => $field,
        'value' => is_scalar($value) || null === $value ? (string) $value : json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
      ];
    }

    self::table($rows);
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
    // --format=json: the rows as they are, for a script to read
    if ('json' === self::$format)
    {
      self::writeJson(array_values(array_map(function ($row) { return (array) $row; }, $rows)));
      return;
    }

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
  * Ask the user to pick one of several answers. $options maps an answer to what it means,
  * the first line being the safest choice. Pressing enter, a closed STDIN (cron, CI) or
  * --yes all take $default, so a script never blocks and never picks the risky branch.
  */
  public static function choose(string $question, array $options, string $default): string
  {
    if (self::$assume_yes)
    {
      return $default;
    }

    self::writeln($question);
    foreach ($options as $answer => $meaning)
    {
      self::writeln('  '.self::green(str_pad($answer, 10)).$meaning.($answer === $default ? ' (default)' : ''));
    }

    $answer = strtolower(self::prompt('['.implode('/', array_keys($options)).']'));

    if (isset($options[$answer]))
    {
      return $answer;
    }

    if ('' !== $answer)
    {
      self::warning('"'.$answer.'" is not one of them, taking "'.$default.'"');
    }

    return $default;
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

  /**
  * Cut a list of rows down to the page the user asked for. The command must declare
  * "'pagination' => true", which gives it --page and --limit. Print the table, then
  * pagination_footer() to tell where the reader is.
  */
  public static function paginate(array $rows, array $args): array
  {
    $page = max(1, (int) $args['page']);
    $limit = max(1, (int) $args['limit']);
    $total = count($rows);

    self::$pagination = [
      'page' => $page,
      'pages' => max(1, (int) ceil($total / $limit)),
      'total' => $total,
    ];

    return array_slice($rows, ($page - 1) * $limit, $limit);
  }

  /**
  * Print where the reader is in a paginated listing, and how to see the next page.
  * Says nothing when everything fits on one page.
  */
  public static function pagination_footer(string $unit = 'result')
  {
    if (0 === count(self::$pagination))
    {
      return;
    }

    $page = self::$pagination['page'];
    $pages = self::$pagination['pages'];
    $total = self::$pagination['total'];
    $counted = $total.' '.$unit.(1 === $total ? '' : 's');

    // asking beyond the end shows an empty table, say why instead of "page 3/2"
    if ($page > $pages)
    {
      self::writeln('there is no page '.$page.', '.$counted.' fit in '.$pages.' page'.(1 === $pages ? '' : 's'));
      return;
    }

    if ($pages < 2)
    {
      return;
    }

    $line = 'page '.$page.'/'.$pages.', '.$counted;

    if ($page < $pages)
    {
      $line .= ', --page '.($page + 1).' for the next';
    }

    self::writeln($line);
  }

  /**
  * Start a progress bar on STDERR. Pass the total when you know it, null for
  * a plain counter. On a terminal the bar redraws in place, elsewhere (cron,
  * redirected output) it prints a line every 10%. Always close it with
  * progress_finish(), or use iterate() which does everything for you. Only
  * one bar at a time: starting a second one throws.
  */
  public static function progress_start(?int $total, string $label = '')
  {
    if (self::$progress_active)
    {
      // a bug in the command, not a runtime condition: be loud
      throw new LogicException('a progress bar is already running ("'.self::$progress_label.'"), finish it before starting another one');
    }

    self::$progress_active = true;
    self::$progress_total = $total;
    self::$progress_current = 0;
    self::$progress_label = $label;
    self::$progress_started = microtime(true);
    self::$progress_drawn = 0.0;
    self::$progress_milestone = -1;
    self::$progress_width = 0;

    self::progress_draw(true);
  }

  /**
  * Move the progress bar forward, by one step unless told otherwise. Cheap to
  * call in a tight loop, it only redraws when the display would change.
  */
  public static function progress_advance(int $step = 1)
  {
    if (!self::$progress_active)
    {
      return;
    }

    self::$progress_current += $step;
    self::progress_draw(false);
  }

  /**
  * Close the progress bar: draws the final state and ends the line. Safe to
  * call twice, a finished bar is simply ignored.
  */
  public static function progress_finish()
  {
    if (!self::$progress_active)
    {
      return;
    }

    if (self::progress_is_tty())
    {
      self::progress_draw(true);
      fwrite(STDERR, PHP_EOL);
    }
    elseif (null === self::$progress_total)
    {
      fwrite(STDERR, trim(self::$progress_label.' done ('.self::$progress_current.')').PHP_EOL);
    }
    elseif (self::progress_percent() !== self::$progress_milestone)
    {
      // the real state, an interrupted bar must not claim 100%
      fwrite(STDERR, self::progress_milestone_line(self::progress_percent()).PHP_EOL);
    }

    self::$progress_active = false;
  }

  /**
  * Change the text shown next to the bar while it runs, to tell where the
  * work is ("Vacances/2024", "step 2/3"...). Does nothing without an active
  * bar.
  */
  public static function progress_label(string $label)
  {
    if (!self::$progress_active)
    {
      return;
    }

    self::$progress_label = $label;

    if (self::progress_is_tty())
    {
      self::progress_draw(true);
    }
  }

  /**
  * Loop over anything with a progress bar, nothing else to call:
  *   foreach (PwgCommand::iterate($files, 'importing') as $file) { ... }
  * The total is taken from count() when it exists. A break or an exception
  * inside the loop still closes the bar properly.
  */
  public static function iterate(iterable $items, string $label = ''): Generator
  {
    self::progress_start(is_countable($items) ? count($items) : null, $label);

    try
    {
      foreach ($items as $key => $item)
      {
        yield $key => $item;
        self::progress_advance();
      }
    }
    finally
    {
      self::progress_finish();
    }
  }

  private static function progress_is_tty(): bool
  {
    if (null === self::$progress_tty)
    {
      self::$progress_tty = stream_isatty(STDERR);
    }

    return self::$progress_tty;
  }

  private static function progress_percent(): int
  {
    if (null === self::$progress_total || self::$progress_total <= 0)
    {
      return 0;
    }

    return (int) min(100, floor(self::$progress_current * 100 / self::$progress_total));
  }

  // one redraw per percent or per 100ms on a terminal, one line per 10% elsewhere
  private static function progress_draw(bool $force)
  {
    $now = microtime(true);
    $percent = self::progress_percent();

    if (!self::progress_is_tty())
    {
      $milestone = null === self::$progress_total
        ? (int) (floor(self::$progress_current / 1000) * 1000)
        : $percent - $percent % 10;

      if ($milestone > self::$progress_milestone && ($force || $milestone > 0))
      {
        self::$progress_milestone = $milestone;
        fwrite(STDERR, self::progress_milestone_line($milestone).PHP_EOL);
      }
      return;
    }

    if (!$force && $percent === self::$progress_milestone && $now - self::$progress_drawn < 0.1)
    {
      return;
    }

    self::$progress_milestone = $percent;
    self::$progress_drawn = $now;

    $line = self::progress_line($now);
    // pad with spaces so a shorter line erases the previous one
    fwrite(STDERR, "\r".str_pad($line, self::$progress_width));
    self::$progress_width = strlen($line);
  }

  private static function progress_clear()
  {
    fwrite(STDERR, "\r".str_repeat(' ', self::$progress_width)."\r");
    self::$progress_width = 0;
  }

  // the milestone line printed off a terminal, e.g. "importing  50% (500/1000)"
  private static function progress_milestone_line(int $milestone): string
  {
    if (null === self::$progress_total)
    {
      return trim(self::$progress_label.'... '.$milestone);
    }

    return trim(self::$progress_label.sprintf(' %3d%% (%d/%d)', $milestone, min(self::$progress_current, self::$progress_total), self::$progress_total));
  }

  // the terminal line, e.g. "[=====>     ]  47%  470/1000  12s  eta 14s  importing"
  private static function progress_line(float $now): string
  {
    $elapsed = $now - self::$progress_started;
    $parts = [];

    if (null === self::$progress_total)
    {
      $spinner = ['-', '\\', '|', '/'];
      $parts[] = '['.$spinner[(int) ($elapsed * 4) % 4].'] '.self::$progress_current;
    }
    else
    {
      $percent = self::progress_percent();
      $filled = (int) floor($percent / 100 * self::PROGRESS_BAR_WIDTH);
      $bar = str_repeat('=', $filled);
      if ($filled < self::PROGRESS_BAR_WIDTH)
      {
        $bar .= '>';
      }
      $parts[] = '['.str_pad($bar, self::PROGRESS_BAR_WIDTH).']';
      $parts[] = sprintf('%3d%%', $percent);
      $parts[] = self::$progress_current.'/'.self::$progress_total;
    }

    $parts[] = self::progress_duration($elapsed);

    // an eta computed on the first instants is noise, wait two seconds
    if (null !== self::$progress_total && self::$progress_current > 0 && $elapsed >= 2 && self::$progress_current < self::$progress_total)
    {
      $remaining = $elapsed / self::$progress_current * (self::$progress_total - self::$progress_current);
      $parts[] = 'eta '.self::progress_duration($remaining);
    }

    if ('' !== self::$progress_label)
    {
      $parts[] = self::$progress_label;
    }

    return implode('  ', $parts);
  }

  private static function progress_duration(float $seconds): string
  {
    $seconds = (int) round($seconds);

    if ($seconds < 60)
    {
      return $seconds.'s';
    }
    if ($seconds < 3600)
    {
      return sprintf('%dm%02ds', intdiv($seconds, 60), $seconds % 60);
    }

    return sprintf('%dh%02dm', intdiv($seconds, 3600), intdiv($seconds % 3600, 60));
  }
}
