<?php

declare(strict_types=1);

namespace SpawnQueue\Console;

use SpawnQueue\ValueObject\QueueConfig;

/**
 * Coloured, live-updating terminal output for SpawnQueue.
 *
 * Layout (TTY mode):
 *
 * ╔══════════════════════════════════════════════╗   ← static header
 * ║  SpawnQueue  >>  Coordinator  PID: 123       ║
 * ╠══════════════════════════════════════════════╣   ┐
 * ║  emails  •  Workers: 2/4  •  Timeout: 60s   ║   │ worker grid (per queue)
 * ╠══════════════════════════════════════════════╣   │
 * ║  1 [ SendEmail#42    ]   2 [ NFEsync#99     ]║   ┘
 * ╠══════════════════════════════════════════════╣   ┐
 * ║  Recent Tasks                                ║   │ combined pending + recent panel
 * ╠══════════════════════════════════════════════╣   │
 * ║ [blk/ylw] #50  ~ pending    ImportJob  …    ║   │ ← pending jobs (top, yellow/black)
 * ║ [grn/wht] #42  ✓ done       SendEmail  …    ║   │ ← done (green bg, white text)
 * ║ [red/wht] #38  ✗ dead       NFESync    …    ║   │ ← dead/failed (red bg, white text)
 * ║ [blk/gry] #35  ↻ retry_wait ImportJob  …    ║   │ ← retry (black bg, gray text)
 * ║  —                                           ║   ┘
 * ╚══════════════════════════════════════════════╝
 *
 * Panel rows (always fixed = MAX_PANEL_ROWS):
 *   • Pending jobs fill the top (capped at MAX_PENDING_SHOWN).
 *   • Recent completed jobs fill the rest.
 *   • Empty rows show a dim dash.
 *
 * Wide mode (innerWidth ≥ 70): task name + HH:MM:SS → HH:MM:SS → HH:MM:SS + (att/max).
 * Narrow mode (innerWidth < 70): no task name, short HH:MM timestamps.
 *
 * Falls back to plain text when stdout is not a TTY.
 * Env: NO_COLOR=1, FORCE_COLOR=1, COLUMNS=N.
 */
class TuiLogger
{
    // -------------------------------------------------------------------------
    // ANSI — foreground / style
    // -------------------------------------------------------------------------
    private const RESET  = "\033[0m";
    private const BOLD   = "\033[1m";
    private const DIM    = "\033[2m";

    private const GRAY          = "\033[90m";
    private const GREEN         = "\033[32m";
    private const YELLOW        = "\033[33m";
    private const MAGENTA       = "\033[35m";
    private const CYAN          = "\033[36m";
    private const BRIGHT_GREEN  = "\033[92m";
    private const BRIGHT_YELLOW = "\033[93m";
    private const BRIGHT_RED    = "\033[91m";
    private const BRIGHT_CYAN   = "\033[96m";
    private const BRIGHT_WHITE  = "\033[97m";

    // ANSI — backgrounds
    private const BG_BLACK  = "\033[40m";
    private const BG_RED    = "\033[41m";
    private const BG_GREEN  = "\033[42m";

    /** Map of event keyword → ANSI style string (for scrolling log lines). */
    private const EVENT_STYLES = [
        'START'            => self::BRIGHT_GREEN . self::BOLD,
        'SPAWNED'          => self::BRIGHT_CYAN,
        'FINISHED'         => self::GREEN,
        'REQUEUED'         => self::BRIGHT_YELLOW,
        'DEAD'             => self::BRIGHT_RED . self::BOLD,
        'TIMEOUT'          => self::BRIGHT_YELLOW,
        'SHUTDOWN'         => self::YELLOW,
        'STOPPED'          => self::DIM,
        'ERROR'            => self::BRIGHT_RED,
        'CRITICAL'         => self::BRIGHT_RED . self::BOLD,
        'WARN'             => self::YELLOW,
        'STUCK_JOBS'       => self::YELLOW,
        'FAILED_JOBS'      => self::YELLOW,
        'PROCESS_REGISTRY' => self::GRAY,
    ];

    // -------------------------------------------------------------------------
    // Layout dimensions — set by computeDimensions() at startup
    // -------------------------------------------------------------------------
    private static int $innerWidth     = 52;
    private static int $slotWidth      = 24;
    private static int $slotLabelWidth = 17;

    // -------------------------------------------------------------------------
    // Live dashboard state
    // -------------------------------------------------------------------------
    private static ?bool  $colorEnabled         = null;
    private static string $showType             = 'lines';
    private static bool   $dashboardReady       = false;
    private static int    $dynamicSectionHeight = 0;
    private static int    $linesAfterBanner     = 0;

    /**
     * @var array<string, array{maxWorkers:int, timeout:int, workerRows:int,
     *                          slots:array<int, array{jobId:int,taskName:string}|null>}>
     */
    private static array $queueStates = [];
    private static array $queueOrder  = [];

    // -------------------------------------------------------------------------
    // Recent Tasks panel state
    // -------------------------------------------------------------------------

    /** Maximum rows in the combined panel (fixed height). */
    private const MAX_PANEL_ROWS = 10;

    /** Maximum pending rows shown at the top of the panel. */
    private const MAX_PENDING_SHOWN = 5;

    /**
     * Per-queue list of pending jobs (newest peek, newest first).
     * @var array<string, list<array{jobId:int, taskName:string, createdAt:string, maxAttempts:int}>>
     */
    private static array $pendingJobs = [];

    /**
     * Ring buffer of recently completed/failed/retried jobs (newest first).
     * @var list<array{queue:string, jobId:int, taskName:string, status:string,
     *                  attempts:int, maxAttempts:int, createdAt:string,
     *                  fetchedAt:string, finishedAt:string}>
     */
    private static array $recentTasks = [];

    // -------------------------------------------------------------------------
    // Public — log methods
    // -------------------------------------------------------------------------

    public static function coordinator(string $queue, string $message): void
    {
        $label = self::c(self::CYAN)
            . '[coordinator:'
            . self::c(self::BRIGHT_CYAN . self::BOLD) . $queue . self::c(self::RESET . self::CYAN)
            . ']'
            . self::c(self::RESET);

        self::emit($label, $message);
    }

    public static function runner(string $message): void
    {
        self::emit(self::c(self::MAGENTA) . '[runner]' . self::c(self::RESET), $message);
    }

    /**
     * Set the output mode before initDashboard() is called.
     *   'lines' — scrolling logs only, no dashboard (default)
     *   'tui'   — live dashboard only, logs suppressed (htop-like)
     */
    public static function setShowType(string $type): void
    {
        self::$showType = $type === 'tui' ? 'tui' : 'lines';
    }

    /** Returns false when log lines should be suppressed (show_type = tui). */
    public static function logsEnabled(): bool
    {
        return self::$showType !== 'tui';
    }

    public static function addLogLines(int $count): void
    {
        self::$linesAfterBanner += $count;
    }

    // -------------------------------------------------------------------------
    // Public — dashboard lifecycle
    // -------------------------------------------------------------------------

    /** @param QueueConfig[] $configs */
    public static function initDashboard(array $configs): void
    {
        self::computeDimensions();

        self::$queueStates  = [];
        self::$queueOrder   = [];
        self::$recentTasks  = [];
        self::$pendingJobs  = [];

        foreach ($configs as $config) {
            $workerRows = (int) ceil($config->maxWorkers / 2);
            self::$queueStates[$config->queue] = [
                'maxWorkers' => $config->maxWorkers,
                'timeout'    => $config->timeout,
                'workerRows' => $workerRows,
                'slots'      => array_fill(1, $config->maxWorkers, null),
            ];
            self::$queueOrder[] = $config->queue;
        }

        self::computeDynamicHeight();

        if (self::$showType === 'lines') {
            $pid   = getmypid();
            $label = count($configs) === 1 ? 'Coordinator' : 'All Queues';
            echo PHP_EOL . "  SpawnQueue  >>  {$label}  PID: {$pid}" . PHP_EOL . PHP_EOL;
            self::$dashboardReady   = false;
            self::$linesAfterBanner = 0;
            return;
        }

        self::printBanner($configs);

        self::$dashboardReady   = self::isColorEnabled();
        self::$linesAfterBanner = 0;
    }

    public static function workerSpawned(string $queue, int $jobId, string $taskName): void
    {
        if (!isset(self::$queueStates[$queue])) {
            return;
        }

        $maxWorkers = self::$queueStates[$queue]['maxWorkers'];
        for ($i = 1; $i <= $maxWorkers; $i++) {
            if (self::$queueStates[$queue]['slots'][$i] === null) {
                self::$queueStates[$queue]['slots'][$i] = [
                    'jobId'    => $jobId,
                    'taskName' => $taskName,
                ];
                break;
            }
        }

        self::redrawDynamic();
    }

    /**
     * Free a worker slot. Call AFTER workerCompleted() so both land in one redraw.
     */
    public static function workerFinished(string $queue, int $jobId): void
    {
        if (!isset(self::$queueStates[$queue])) {
            return;
        }

        foreach (self::$queueStates[$queue]['slots'] as $slot => $info) {
            if ($info !== null && $info['jobId'] === $jobId) {
                self::$queueStates[$queue]['slots'][$slot] = null;
                break;
            }
        }

        self::redrawDynamic();
    }

    /**
     * Add a completed job to the Recent Tasks panel.
     * Call BEFORE workerFinished() so both land in the same redraw.
     */
    public static function workerCompleted(
        string $queue,
        int    $jobId,
        string $taskName,
        string $status,
        int    $attempts,
        int    $maxAttempts,
        string $createdAt,
        string $fetchedAt,
        string $finishedAt,
    ): void {
        array_unshift(self::$recentTasks, compact(
            'queue', 'jobId', 'taskName', 'status', 'attempts', 'maxAttempts',
            'createdAt', 'fetchedAt', 'finishedAt'
        ));

        // Cap the ring buffer to leave room for pending rows at the top.
        if (count(self::$recentTasks) > self::MAX_PANEL_ROWS) {
            array_pop(self::$recentTasks);
        }
    }

    /**
     * Update the pending jobs list for a queue and redraw if it changed.
     * Called by the coordinator on every heartbeat.
     *
     * @param list<array{jobId:int, taskName:string, createdAt:string, maxAttempts:int}> $jobs
     */
    public static function setPendingJobs(string $queue, array $jobs): void
    {
        $sliced = array_slice($jobs, 0, self::MAX_PENDING_SHOWN);

        // Avoid noisy redraws when nothing changed.
        if (array_column($sliced, 'jobId') === array_column(self::$pendingJobs[$queue] ?? [], 'jobId')) {
            return;
        }

        self::$pendingJobs[$queue] = $sliced;
        self::redrawDynamic();
    }

    // -------------------------------------------------------------------------
    // Private — terminal size detection
    // -------------------------------------------------------------------------

    private static function computeDimensions(): void
    {
        $term  = self::detectTerminalWidth();
        $inner = max(50, min($term - 2, 120));
        $sw    = (int) (($inner - 4) / 2);
        $inner = $sw * 2 + 4;

        self::$innerWidth     = $inner;
        self::$slotWidth      = $sw;
        self::$slotLabelWidth = max(8, $sw - 7);
    }

    private static function detectTerminalWidth(): int
    {
        $cols = (int) getenv('COLUMNS');
        if ($cols > 20) {
            return $cols;
        }

        if (PHP_OS_FAMILY !== 'Windows') {
            $tput = (int) @shell_exec('tput cols 2>/dev/null');
            if ($tput > 20) {
                return $tput;
            }

            $stty = (string) @shell_exec('stty size 2>/dev/null');
            if ($stty !== '' && sscanf($stty, '%d %d', $rows, $cols) === 2 && (int) $cols > 20) {
                return (int) $cols;
            }
        }

        if (PHP_OS_FAMILY === 'Windows') {
            $mode = (string) @shell_exec('mode con');
            if ($mode !== '' && preg_match('/Columns[:\s]+(\d+)/i', $mode, $m)) {
                $cols = (int) $m[1];
                if ($cols > 20) {
                    return $cols;
                }
            }
        }

        return 80;
    }

    // -------------------------------------------------------------------------
    // Private — banner
    // -------------------------------------------------------------------------

    /** @param QueueConfig[] $configs */
    private static function printBanner(array $configs): void
    {
        $w     = self::$innerWidth;
        $pid   = getmypid();
        $label = count($configs) === 1 ? 'Coordinator' : 'All Queues';

        if (!self::isColorEnabled()) {
            $sep = str_repeat('=', $w + 2);
            echo PHP_EOL . $sep . PHP_EOL;
            echo "  SpawnQueue  >>  {$label}  PID: {$pid}" . PHP_EOL;
            self::echoStaticDynamic(false);
            return;
        }

        $top      = self::CYAN . self::BOLD . '╔' . str_repeat('═', $w) . '╗' . self::RESET;
        $title    = "  SpawnQueue  >>  {$label}  PID: {$pid}";
        $titleRow = self::CYAN . self::BOLD . '║' . self::RESET
            . self::BRIGHT_WHITE . self::BOLD . self::padTo($title, $w) . self::RESET
            . self::CYAN . self::BOLD . '║' . self::RESET;

        echo PHP_EOL;
        echo $top . PHP_EOL;
        echo $titleRow . PHP_EOL;
        self::echoStaticDynamic(true);
    }

    private static function echoStaticDynamic(bool $color): void
    {
        $w = self::$innerWidth;

        $divider = $color
            ? self::CYAN . self::BOLD . '╠' . str_repeat('═', $w) . '╣' . self::RESET
            : str_repeat('-', $w + 2);

        $bottom = $color
            ? self::CYAN . self::BOLD . '╚' . str_repeat('═', $w) . '╝' . self::RESET
            : str_repeat('=', $w + 2);

        foreach (self::$queueOrder as $queue) {
            $state  = self::$queueStates[$queue];
            $active = count(array_filter($state['slots']));
            $info   = "  {$queue}  •  Workers: {$active}/{$state['maxWorkers']}  •  Timeout: {$state['timeout']}s";

            if ($color) {
                $infoRow = self::CYAN . self::BOLD . '║' . self::RESET
                    . self::GRAY . self::padTo($info, $w) . self::RESET
                    . self::CYAN . self::BOLD . '║' . self::RESET;
            } else {
                $infoRow = '|' . self::padTo($info, $w) . '|';
            }

            echo $divider . PHP_EOL;
            echo $infoRow . PHP_EOL;
            echo $divider . PHP_EOL;

            foreach (self::buildWorkerRowStrings($queue, $color) as $row) {
                if ($color) {
                    echo self::CYAN . self::BOLD . '║' . self::RESET
                        . $row
                        . self::CYAN . self::BOLD . '║' . self::RESET . PHP_EOL;
                } else {
                    echo '|' . $row . '|' . PHP_EOL;
                }
            }
        }

        foreach (self::buildTaskPanel($color) as $line) {
            echo $line . PHP_EOL;
        }

        echo $bottom . PHP_EOL;
    }

    // -------------------------------------------------------------------------
    // Private — live redraw
    // -------------------------------------------------------------------------

    /**
     * Dynamic section height:
     *   Per-queue: ╠(1) + info(1) + ╠(1) + workerRows = workerRows + 3
     *   Panel:     ╠(1) + header(1) + ╠(1) + MAX_PANEL_ROWS = MAX_PANEL_ROWS + 3
     *   Bottom:    1
     */
    private static function computeDynamicHeight(): void
    {
        $h = 1; // bottom border
        foreach (self::$queueStates as $state) {
            $h += 3 + $state['workerRows'];
        }
        $h += 3 + self::MAX_PANEL_ROWS;
        self::$dynamicSectionHeight = $h;
    }

    private static function redrawDynamic(): void
    {
        if (!self::$dashboardReady) {
            return;
        }

        $w       = self::$innerWidth;
        $linesUp = self::$linesAfterBanner + self::$dynamicSectionHeight;

        echo "\033[{$linesUp}A";

        $divider = self::CYAN . self::BOLD . '╠' . str_repeat('═', $w) . '╣' . self::RESET;
        $bottom  = self::CYAN . self::BOLD . '╚' . str_repeat('═', $w) . '╝' . self::RESET;

        foreach (self::$queueOrder as $queue) {
            $state  = self::$queueStates[$queue];
            $active = count(array_filter($state['slots']));
            $info   = "  {$queue}  •  Workers: {$active}/{$state['maxWorkers']}  •  Timeout: {$state['timeout']}s";

            $infoRow = self::CYAN . self::BOLD . '║' . self::RESET
                . self::GRAY . self::padTo($info, $w) . self::RESET
                . self::CYAN . self::BOLD . '║' . self::RESET;

            echo "\r\033[2K" . $divider . "\n";
            echo "\r\033[2K" . $infoRow . "\n";
            echo "\r\033[2K" . $divider . "\n";

            foreach (self::buildWorkerRowStrings($queue, true) as $row) {
                echo "\r\033[2K"
                    . self::CYAN . self::BOLD . '║' . self::RESET
                    . $row
                    . self::CYAN . self::BOLD . '║' . self::RESET
                    . "\n";
            }
        }

        foreach (self::buildTaskPanel(true) as $line) {
            echo "\r\033[2K" . $line . "\n";
        }

        echo "\r\033[2K" . $bottom . "\n";

        if (self::$linesAfterBanner > 0) {
            echo "\033[" . self::$linesAfterBanner . "B";
        }
        echo "\r";
    }

    // -------------------------------------------------------------------------
    // Private — worker slot rendering
    // -------------------------------------------------------------------------

    /** @return string[] */
    private static function buildWorkerRowStrings(string $queue, bool $color): array
    {
        $state = self::$queueStates[$queue];
        $rows  = [];
        $sw    = self::$slotWidth;

        for ($row = 0; $row < $state['workerRows']; $row++) {
            $s1 = $row * 2 + 1;
            $s2 = $s1 + 1;

            $rendered1 = self::renderSlot($s1, $state['slots'][$s1] ?? null, $color);
            $rendered2 = $s2 <= $state['maxWorkers']
                ? self::renderSlot($s2, $state['slots'][$s2] ?? null, $color)
                : str_repeat(' ', $sw);

            $rows[] = ' ' . $rendered1 . '  ' . $rendered2 . ' ';
        }

        return $rows;
    }

    private static function renderSlot(int $num, ?array $info, bool $color): string
    {
        $numStr = str_pad((string) $num, 2);
        $inner  = self::$slotWidth - 5;

        if ($info === null) {
            $blank = str_repeat(' ', $inner);
            return $color
                ? self::DIM . self::GRAY . $numStr . ' [' . $blank . ']' . self::RESET
                : $numStr . ' [' . $blank . ']';
        }

        $label  = self::slotLabel($info['jobId'], $info['taskName']);
        $padded = str_pad($label, self::$slotLabelWidth);

        if ($color) {
            return self::GRAY . $numStr . self::RESET
                . self::BRIGHT_GREEN . ' [' . self::RESET
                . ' '
                . self::BRIGHT_WHITE . self::BOLD . $padded . self::RESET
                . self::BRIGHT_GREEN . ' ]' . self::RESET;
        }

        return $numStr . ' [ ' . $padded . ' ]';
    }

    private static function slotLabel(int $jobId, string $taskName): string
    {
        $short   = basename(str_replace('\\', '/', $taskName));
        $short   = (string) (preg_replace('/(Handler|Task|Job)$/', '', $short) ?: $short);
        $idPart  = '#' . $jobId;
        $maxName = self::$slotLabelWidth - strlen($idPart);

        if ($maxName < 2) {
            return substr($short . $idPart, 0, self::$slotLabelWidth);
        }

        if (strlen($short) > $maxName) {
            $short = substr($short, 0, $maxName - 1) . '~';
        }

        return $short . $idPart;
    }

    // -------------------------------------------------------------------------
    // Private — Recent Tasks + Pending panel
    // -------------------------------------------------------------------------

    /**
     * Builds the complete panel: ╠ header ╠ + MAX_PANEL_ROWS rows.
     * Pending jobs (yellow/black) fill the top; recent completed fill the rest.
     * Always returns exactly MAX_PANEL_ROWS + 3 lines → height stays fixed.
     *
     * @return string[]
     */
    private static function buildTaskPanel(bool $color): array
    {
        $w = self::$innerWidth;

        $divider = $color
            ? self::CYAN . self::BOLD . '╠' . str_repeat('═', $w) . '╣' . self::RESET
            : str_repeat('-', $w + 2);

        $headerText = '  Recent Tasks';
        $headerRow  = $color
            ? self::CYAN . self::BOLD . '║' . self::RESET
                . self::BRIGHT_WHITE . self::BOLD . self::padTo($headerText, $w) . self::RESET
                . self::CYAN . self::BOLD . '║' . self::RESET
            : '|' . self::padTo($headerText, $w) . '|';

        $lines = [$divider, $headerRow, $divider];

        // Flatten pending jobs across all queues (in queue config order).
        $allPending = [];
        foreach (self::$queueOrder as $q) {
            foreach (self::$pendingJobs[$q] ?? [] as $job) {
                $allPending[] = $job + ['queue' => $q];
                if (count($allPending) >= self::MAX_PENDING_SHOWN) {
                    break 2;
                }
            }
        }

        $pendingCount = count($allPending);

        for ($i = 0; $i < self::MAX_PANEL_ROWS; $i++) {
            if ($i < $pendingCount) {
                $lines[] = self::buildTaskRow('pending', $allPending[$i], $color);
            } elseif (($ri = $i - $pendingCount) < count(self::$recentTasks)) {
                $lines[] = self::buildTaskRow(self::$recentTasks[$ri]['status'], self::$recentTasks[$ri], $color);
            } else {
                $lines[] = self::buildEmptyRow($color);
            }
        }

        return $lines;
    }

    /**
     * Builds one panel row.
     *
     * Color is applied ONLY to the status badge (icon + text), e.g.:
     *   #42  [grn/wht]✓ done[/reset]       SendEmail  12:30:01 → 12:30:05 → 12:30:23  (1/3)
     *
     * Badge color per status:
     *   done       → green bg  + bright white
     *   failed/dead→ red bg    + bright white
     *   retry_wait → black bg  + dim gray
     *   pending    → black bg  + bright yellow
     */
    private static function buildTaskRow(string $status, array $data, bool $color): string
    {
        $w = self::$innerWidth;

        // ── status badge (always 12 visible chars: icon + space + text padded to 10) ─
        [$icon, $statusText] = self::taskStatusBadge($status);
        $badge = $icon . ' ' . str_pad($statusText, 10);

        // ── job id ────────────────────────────────────────────────────
        $jobPart = str_pad('#' . $data['jobId'], 5);

        // ── attempts ──────────────────────────────────────────────────
        $attPart = $status === 'pending'
            ? '(—)'
            : '(' . $data['attempts'] . '/' . $data['maxAttempts'] . ')';

        // ── timestamps ───────────────────────────────────────────────
        if ($status === 'pending') {
            $timesWide   = self::fmtTime($data['createdAt']) . ' → --:--:-- → --:--:--';
            $timesNarrow = self::fmtTime($data['createdAt'], true) . ' --:-- --:--';
        } else {
            $timesWide   = self::fmtTime($data['createdAt'])
                . ' → ' . self::fmtTime($data['fetchedAt'])
                . ' → ' . self::fmtTime($data['finishedAt']);
            $timesNarrow = self::fmtTime($data['createdAt'], true)
                . ' ' . self::fmtTime($data['fetchedAt'], true)
                . ' ' . self::fmtTime($data['finishedAt'], true);
        }

        // ── wide vs narrow: fixed visible chars (excluding task column) ─
        // 2 + jobPartWidth + 2 + 12 + 2 + [task] + 2 + len(times) + 2 + len(att) + 1
        // Use mb_strlen for timesWide/attPart: they contain multi-byte chars (→, —).
        $jobPartWidth = strlen($jobPart); // ASCII-only, strlen == visible width
        $fixedWide    = 23 + $jobPartWidth + mb_strlen($timesWide, 'UTF-8') + mb_strlen($attPart, 'UTF-8');
        $taskWidth    = $w - $fixedWide; // >= 4 → show task name

        // ── no-color path ─────────────────────────────────────────────
        if (!$color) {
            if ($taskWidth >= 4) {
                $plain = '  ' . $jobPart . '  ' . $badge
                    . '  ' . str_pad(self::shortName($data['taskName'] ?? '', $taskWidth), $taskWidth)
                    . '  ' . $timesWide . '  ' . $attPart . ' ';
            } else {
                $plain = '  ' . $jobPart . '  ' . $badge
                    . '  ' . $timesNarrow . '  ' . $attPart . ' ';
            }
            return '|' . self::padTo($plain, $w) . '|';
        }

        // ── color path: badge-only highlight, rest → terminal default ──
        [$bgCode, $fgCode] = self::taskRowColors($status);
        $coloredBadge = $bgCode . $fgCode . $badge . self::RESET;

        if ($taskWidth >= 4) {
            $taskName     = self::shortName($data['taskName'] ?? '', $taskWidth);
            $coloredInner = '  '
                . self::GRAY . $jobPart . self::RESET
                . '  '
                . $coloredBadge
                . '  '
                . self::BRIGHT_WHITE . str_pad($taskName, $taskWidth) . self::RESET
                . '  '
                . self::GRAY . $timesWide . self::RESET
                . '  '
                . self::DIM . $attPart . self::RESET
                . ' ';
        } else {
            $coloredInner = '  '
                . self::GRAY . $jobPart . self::RESET
                . '  '
                . $coloredBadge
                . '  '
                . self::GRAY . $timesNarrow . self::RESET
                . '  '
                . self::DIM . $attPart . self::RESET
                . ' ';
        }

        return self::CYAN . self::BOLD . '║' . self::RESET
            . self::padTo($coloredInner, $w)
            . self::CYAN . self::BOLD . '║' . self::RESET;
    }

    private static function buildEmptyRow(bool $color): string
    {
        $w = self::$innerWidth;
        if (!$color) {
            return '|' . self::padTo('  —', $w) . '|';
        }

        return self::CYAN . self::BOLD . '║' . self::RESET
            . self::padTo(self::DIM . self::GRAY . '  —' . self::RESET, $w)
            . self::CYAN . self::BOLD . '║' . self::RESET;
    }

    /**
     * Returns [bgAnsiCode, fgAnsiCode] applied exclusively to the status badge.
     *   done       → green bg  + bright white
     *   failed/dead→ red bg    + bright white
     *   retry_wait → black bg  + dim gray
     *   pending    → black bg  + bright yellow
     */
    private static function taskRowColors(string $status): array
    {
        return match ($status) {
            'done'                  => [self::BG_GREEN, self::BRIGHT_WHITE],
            'failed', 'dead'        => [self::BG_RED,   self::BRIGHT_WHITE],
            'retry_wait'            => [self::BG_BLACK,  self::GRAY],
            'pending'               => [self::BG_BLACK,  self::BRIGHT_YELLOW],
            default                 => ['',              ''],
        };
    }

    /**
     * Returns [icon (1 visible char), status text (≤ 10 chars)] for a status.
     */
    private static function taskStatusBadge(string $status): array
    {
        return match ($status) {
            'done'       => ['✓', 'done'],
            'retry_wait' => ['↻', 'retry_wait'],
            'failed'     => ['✗', 'failed'],
            'dead'       => ['✗', 'dead'],
            'pending'    => ['~', 'pending'],
            default      => ['?', mb_substr($status, 0, 10)],
        };
    }

    /**
     * Strips namespace and Handler/Task/Job suffix, truncates to $max chars with '~'.
     */
    private static function shortName(string $taskName, int $max): string
    {
        if ($max < 1) {
            return '';
        }
        $short = basename(str_replace('\\', '/', $taskName));
        $short = (string) (preg_replace('/(Handler|Task|Job)$/', '', $short) ?: $short);

        return mb_strlen($short) > $max
            ? mb_substr($short, 0, $max - 1) . '~'
            : $short;
    }

    /**
     * Formats a datetime string to HH:MM:SS (or HH:MM when $short = true).
     */
    private static function fmtTime(string $datetime, bool $short = false): string
    {
        $empty = $short ? '--:--' : '--:--:--';
        if ($datetime === '' || str_starts_with($datetime, '0000')) {
            return $empty;
        }
        $ts = strtotime($datetime);
        return $ts !== false ? date($short ? 'H:i' : 'H:i:s', $ts) : $empty;
    }

    // -------------------------------------------------------------------------
    // Private — log colorisation
    // -------------------------------------------------------------------------

    private static function emit(string $context, string $message): void
    {
        if (self::$showType === 'tui') {
            return;
        }

        $ts = date('Y-m-d H:i:s');
        echo self::c(self::GRAY) . "[{$ts}]" . self::c(self::RESET)
            . ' ' . $context
            . ' ' . self::colorizeMessage($message)
            . PHP_EOL;

        self::$linesAfterBanner++;
    }

    private static function colorizeMessage(string $message): string
    {
        if (!self::isColorEnabled()) {
            return $message;
        }

        $firstSpace = strpos($message, ' ');
        $keyword    = $firstSpace !== false ? substr($message, 0, $firstSpace) : $message;
        $rest       = $firstSpace !== false ? substr($message, $firstSpace) : '';

        if ($keyword === 'END') {
            if (str_contains($message, 'status=done')) {
                return self::BRIGHT_GREEN . self::BOLD . $keyword . self::RESET . self::colorizeKV($rest);
            }
            if (str_contains($message, 'status=retry_wait')) {
                return self::YELLOW . $keyword . self::RESET . self::colorizeKV($rest);
            }
            if (str_contains($message, 'status=dead') || str_contains($message, 'status=failed')) {
                return self::BRIGHT_RED . self::BOLD . $keyword . self::RESET . self::colorizeKV($rest);
            }
            return self::CYAN . $keyword . self::RESET . self::colorizeKV($rest);
        }

        $style = self::EVENT_STYLES[$keyword] ?? null;
        return $style !== null
            ? $style . $keyword . self::RESET . self::colorizeKV($rest)
            : self::colorizeKV($message);
    }

    private static function colorizeKV(string $text): string
    {
        if (!self::isColorEnabled()) {
            return $text;
        }

        return preg_replace_callback(
            '/\b([a-z_]+)=(\S+)/',
            fn(array $m) => self::GRAY . $m[1] . '=' . self::RESET . self::BRIGHT_WHITE . $m[2] . self::RESET,
            $text
        ) ?? $text;
    }

    // -------------------------------------------------------------------------
    // Private — string / terminal helpers
    // -------------------------------------------------------------------------

    private static function padTo(string $text, int $width): string
    {
        $visible = mb_strlen(self::stripAnsi($text), 'UTF-8');
        return $visible >= $width
            ? mb_substr($text, 0, $width, 'UTF-8')
            : $text . str_repeat(' ', $width - $visible);
    }

    private static function stripAnsi(string $text): string
    {
        return preg_replace('/\033\[[0-9;]*m/', '', $text) ?? $text;
    }

    private static function c(string $code): string
    {
        return self::isColorEnabled() ? $code : '';
    }

    private static function isColorEnabled(): bool
    {
        if (self::$colorEnabled !== null) {
            return self::$colorEnabled;
        }
        if (getenv('NO_COLOR') !== false) {
            return self::$colorEnabled = false;
        }
        if (getenv('FORCE_COLOR') !== false) {
            return self::$colorEnabled = true;
        }
        if (function_exists('posix_isatty') && !posix_isatty(STDOUT)) {
            return self::$colorEnabled = false;
        }
        return self::$colorEnabled = true;
    }
}
