<?php
/**
 * カレンダー表示用の共通ヘルパー。
 *
 * 勤務可能日・シフト一覧など、日付を軸にした一覧を
 * 月表示 / 週表示のカレンダーUIとして表示する。
 */

function getCalendarView(): string
{
    // 週表示の描画処理は残すが、画面上の導線は月表示のみにする。
    return 'month';
}

function getCalendarMonth(): string
{
    $month = (string) ($_GET['calendar_month'] ?? date('Y-m'));
    if (preg_match('/^\d{4}-\d{2}$/', $month) !== 1) {
        return date('Y-m');
    }

    return $month;
}

function getCalendarFocusDate(string $targetMonth): string
{
    $focusDate = (string) ($_GET['calendar_date'] ?? '');
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $focusDate) === 1 && str_starts_with($focusDate, $targetMonth)) {
        return $focusDate;
    }

    if ($targetMonth === date('Y-m')) {
        return date('Y-m-d');
    }

    return $targetMonth . '-01';
}

function calendarUrl(string $baseFile, string $month, string $view, ?string $focusDate = null): string
{
    $params = [
        'calendar_month' => $month,
        'calendar_view'  => $view,
    ];

    if ($focusDate !== null) {
        $params['calendar_date'] = $focusDate;
    }

    return $baseFile . '?' . http_build_query($params);
}

function formatCalendarTime(?string $time): string
{
    if ($time === null || $time === '') {
        return '';
    }

    return substr($time, 0, 5);
}

function calendarTimeToMinutes(?string $time): ?int
{
    if ($time === null || $time === '') {
        return null;
    }

    $parts = explode(':', $time);
    if (count($parts) < 2 || !is_numeric($parts[0]) || !is_numeric($parts[1])) {
        return null;
    }

    return ((int) $parts[0] * 60) + (int) $parts[1];
}

/**
 * @param string $toolbarHtml 月切替の右側に表示する追加ボタン等のHTML
 *                            （例: 「＋ 勤務可能日を登録」ボタンとその詳細ソースdiv）。
 */
function renderCalendarControls(string $targetMonth, string $view, string $baseFile, string $toolbarHtml = ''): void
{
    $target = new DateTimeImmutable($targetMonth . '-01');
    $previousMonth = $target->modify('-1 month')->format('Y-m');
    $nextMonth = $target->modify('+1 month')->format('Y-m');
    ?>
    <div id="calendar-ui" class="calendar-controls" data-calendar-preserve-scroll>
        <div class="calendar-month-nav">
            <a class="btn btn-secondary" href="<?php echo htmlspecialchars(calendarUrl($baseFile, $previousMonth, 'month')); ?>">← 前の月</a>
            <a class="btn btn-secondary" href="<?php echo htmlspecialchars(calendarUrl($baseFile, $nextMonth, 'month')); ?>">次の月 →</a>
        </div>
        <?php if ($toolbarHtml !== ''): ?>
        <div class="calendar-toolbar">
            <?php echo $toolbarHtml; ?>
        </div>
        <?php endif; ?>
    </div>
    <?php
}

function getCalendarWeeks(string $targetMonth, string $view): array
{
    $firstDay = new DateTimeImmutable($targetMonth . '-01');

    if ($view === 'week') {
        $focus = new DateTimeImmutable(getCalendarFocusDate($targetMonth));
        $start = $focus->modify('-' . (int) $focus->format('w') . ' days');
        return [buildCalendarWeek($start)];
    }

    $lastDay = $firstDay->modify('last day of this month');
    $start = $firstDay->modify('-' . (int) $firstDay->format('w') . ' days');
    $end = $lastDay->modify('+' . (6 - (int) $lastDay->format('w')) . ' days');

    $weeks = [];
    $cursor = $start;
    while ($cursor <= $end) {
        $weeks[] = buildCalendarWeek($cursor);
        $cursor = $cursor->modify('+7 days');
    }

    return $weeks;
}

function buildCalendarWeek(DateTimeImmutable $start): array
{
    $week = [];
    for ($i = 0; $i < 7; $i++) {
        $week[] = $start->modify('+' . $i . ' days');
    }

    return $week;
}

function renderCalendarGrid(array $eventsByDate, string $targetMonth, string $view, string $emptyText): void
{
    $weeks = getCalendarWeeks($targetMonth, $view);
    $weekdays = ['日', '月', '火', '水', '木', '金', '土'];
    $hasEvents = false;
    foreach ($eventsByDate as $events) {
        if (!empty($events)) {
            $hasEvents = true;
            break;
        }
    }
    ?>
    <div class="calendar-wrapper" data-calendar-wrapper>
        <div class="calendar-heading">
            <?php echo htmlspecialchars((new DateTimeImmutable($targetMonth . '-01'))->format('Y年n月')); ?>
        </div>

        <?php if ($view === 'week'): ?>
            <?php renderCalendarWeekTimeGrid($eventsByDate, $weeks[0], $weekdays); ?>
        <?php else: ?>
            <?php renderCalendarMonthGrid($eventsByDate, $targetMonth, $weeks, $weekdays); ?>
        <?php endif; ?>

        <?php if (!$hasEvents): ?>
        <p class="page-description calendar-empty-message"><?php echo htmlspecialchars($emptyText); ?></p>
        <?php endif; ?>

        <div class="calendar-modal" data-calendar-modal hidden>
            <div class="calendar-modal-backdrop" data-calendar-close></div>
            <div class="calendar-modal-panel" role="dialog" aria-modal="true" aria-labelledby="calendar-modal-title">
                <button type="button" class="calendar-modal-close" data-calendar-close aria-label="閉じる">×</button>
                <h3 id="calendar-modal-title" data-calendar-modal-title>詳細</h3>
                <div class="calendar-modal-body" data-calendar-modal-body></div>
            </div>
        </div>
    </div>

    <script>
    if (!window.shiftCalendarInitialized) {
        window.shiftCalendarInitialized = true;
        const calendarScrollKey = 'shiftCalendarScroll:' + location.pathname;

        window.addEventListener('DOMContentLoaded', function () {
            const savedY = sessionStorage.getItem(calendarScrollKey);
            if (savedY !== null) {
                sessionStorage.removeItem(calendarScrollKey);
                window.scrollTo(0, Number(savedY));
            }
        });

        document.addEventListener('click', function (event) {
            const eventButton = event.target.closest('[data-calendar-detail]');
            if (eventButton) {
                // モーダルを開くボタンは data-calendar-wrapper の外（月切替の隣など）に
                // 置かれる場合もあるため、closest ではなくページ全体から探す
                // （1ページにつきカレンダーは1つしか表示しない前提）。
                const modal = document.querySelector('[data-calendar-modal]');
                if (!modal) {
                    return;
                }
                const panel = modal.querySelector('.calendar-modal-panel');
                const title = modal.querySelector('[data-calendar-modal-title]');
                const body = modal.querySelector('[data-calendar-modal-body]');
                const detail = document.getElementById(eventButton.dataset.calendarDetail);

                if (panel) {
                    panel.classList.remove('calendar-modal-wide');
                    if (eventButton.dataset.calendarModalClass) {
                        panel.classList.add(eventButton.dataset.calendarModalClass);
                    }
                }
                title.textContent = eventButton.dataset.calendarTitle || '詳細';
                body.innerHTML = detail ? detail.innerHTML : '';
                modal.hidden = false;
                document.body.classList.add('calendar-modal-open');
                return;
            }

            const calendarLink = event.target.closest('[data-calendar-preserve-scroll] a');
            if (calendarLink) {
                sessionStorage.setItem(calendarScrollKey, String(window.scrollY));
                return;
            }

            if (event.target.closest('[data-calendar-close]')) {
                const modal = event.target.closest('[data-calendar-modal]');
                if (modal) {
                    modal.hidden = true;
                    const body = modal.querySelector('[data-calendar-modal-body]');
                    if (body) {
                        body.innerHTML = '';
                    }
                    document.body.classList.remove('calendar-modal-open');
                }
            }
        });

        document.addEventListener('submit', function () {
            // ポップアップ内のフォームはモーダル領域にコピーされて表示されるため
            // data-calendar-preserve-scroll の外側になる。送信元を問わず、
            // このページ内のフォーム送信であれば常にスクロール位置を保存する。
            sessionStorage.setItem(calendarScrollKey, String(window.scrollY));
        });

        document.addEventListener('change', function (event) {
            if (event.target.closest('[data-calendar-preserve-scroll]')) {
                sessionStorage.setItem(calendarScrollKey, String(window.scrollY));
            }
        });

        document.addEventListener('keydown', function (event) {
            if (event.key !== 'Escape') {
                return;
            }

            document.querySelectorAll('[data-calendar-modal]').forEach(function (modal) {
                modal.hidden = true;
                const body = modal.querySelector('[data-calendar-modal-body]');
                if (body) {
                    body.innerHTML = '';
                }
            });
            document.body.classList.remove('calendar-modal-open');
        });
    }
    </script>
    <?php
}

function renderCalendarMonthGrid(array $eventsByDate, string $targetMonth, array $weeks, array $weekdays): void
{
    ?>
    <div class="calendar-grid calendar-grid-month">
        <?php foreach ($weekdays as $weekday): ?>
        <div class="calendar-weekday"><?php echo htmlspecialchars($weekday); ?></div>
        <?php endforeach; ?>

        <?php foreach ($weeks as $week): ?>
            <?php foreach ($week as $day): ?>
            <?php
            $date = $day->format('Y-m-d');
            $events = $eventsByDate[$date] ?? [];
            $classes = ['calendar-day'];
            if ($day->format('Y-m') !== $targetMonth) {
                $classes[] = 'is-outside-month';
            }
            if ($date === date('Y-m-d')) {
                $classes[] = 'is-today';
            }
            ?>
            <div class="<?php echo htmlspecialchars(implode(' ', $classes)); ?>">
                <?php renderCalendarDayHeader($day, $events); ?>
                <div class="calendar-events">
                    <?php foreach ($events as $index => $event): ?>
                    <?php renderCalendarEventButton($date, $index, $event); ?>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php endforeach; ?>
        <?php endforeach; ?>
    </div>
    <?php
}

function renderCalendarWeekTimeGrid(array $eventsByDate, array $week, array $weekdays): void
{
    $range = getCalendarWeekHourRange($eventsByDate, $week);
    $startHour = $range['start'];
    $endHour = $range['end'];
    $hourHeight = 64;
    $totalHeight = max(1, $endHour - $startHour) * $hourHeight;
    ?>
    <div class="calendar-time-grid" style="--calendar-hour-height: <?php echo (int) $hourHeight; ?>px; --calendar-total-height: <?php echo (int) $totalHeight; ?>px;">
        <div class="calendar-time-grid-corner"></div>
        <?php foreach ($week as $index => $day): ?>
        <?php
        $date = $day->format('Y-m-d');
        $events = $eventsByDate[$date] ?? [];
        ?>
        <div class="calendar-time-day-header <?php echo $date === date('Y-m-d') ? 'is-today' : ''; ?>">
            <?php renderCalendarDayHeader($day, $events, $weekdays[$index]); ?>
        </div>
        <?php endforeach; ?>

        <div class="calendar-time-axis">
            <?php for ($hour = $startHour; $hour <= $endHour; $hour++): ?>
            <div class="calendar-time-label" style="top: <?php echo (int) (($hour - $startHour) * $hourHeight); ?>px;">
                <?php echo htmlspecialchars(sprintf('%02d:00', $hour)); ?>
            </div>
            <?php endfor; ?>
        </div>

        <?php foreach ($week as $day): ?>
        <?php
        $date = $day->format('Y-m-d');
        $events = $eventsByDate[$date] ?? [];
        ?>
        <div class="calendar-time-day-column <?php echo $date === date('Y-m-d') ? 'is-today' : ''; ?>">
            <div class="calendar-time-hour-lines">
                <?php for ($hour = $startHour; $hour < $endHour; $hour++): ?>
                <div class="calendar-time-hour-line"></div>
                <?php endfor; ?>
            </div>

            <?php foreach ($events as $index => $event): ?>
            <?php renderCalendarTimedEvent($date, $index, $event, $startHour, $hourHeight); ?>
            <?php endforeach; ?>
        </div>
        <?php endforeach; ?>
    </div>
    <?php
}

function getCalendarWeekHourRange(array $eventsByDate, array $week): array
{
    $minMinutes = null;
    $maxMinutes = null;

    foreach ($week as $day) {
        $date = $day->format('Y-m-d');
        foreach (($eventsByDate[$date] ?? []) as $event) {
            $start = calendarTimeToMinutes($event['start_time'] ?? null);
            $end = calendarTimeToMinutes($event['end_time'] ?? null);
            if ($start === null || $end === null) {
                continue;
            }

            $minMinutes = $minMinutes === null ? $start : min($minMinutes, $start);
            $maxMinutes = $maxMinutes === null ? $end : max($maxMinutes, $end);
        }
    }

    if ($minMinutes === null || $maxMinutes === null) {
        return ['start' => 8, 'end' => 24];
    }

    $startHour = max(0, (int) floor($minMinutes / 60) - 1);
    $endHour = min(24, (int) ceil($maxMinutes / 60) + 1);

    if ($endHour <= $startHour) {
        $endHour = min(24, $startHour + 1);
    }

    return ['start' => $startHour, 'end' => $endHour];
}

function renderCalendarDayHeader(DateTimeImmutable $day, array $events, ?string $weekday = null): void
{
    $date = $day->format('Y-m-d');
    $dayDetailId = 'calendar-day-detail-' . md5($date);
    $label = ($weekday !== null ? $weekday . ' ' : '') . $day->format('j');
    ?>
    <?php if (!empty($events)): ?>
    <button
        type="button"
        class="calendar-day-number calendar-day-button"
        data-calendar-title="<?php echo htmlspecialchars($day->format('Y年n月j日') . 'の詳細'); ?>"
        data-calendar-detail="<?php echo htmlspecialchars($dayDetailId); ?>"
    >
        <?php echo htmlspecialchars($label); ?>
    </button>
    <div id="<?php echo htmlspecialchars($dayDetailId); ?>" class="calendar-detail-source" hidden>
        <?php foreach ($events as $event): ?>
        <div class="calendar-day-detail-item">
            <h4><?php echo htmlspecialchars($event['title'] ?? '詳細'); ?></h4>
            <?php if (!empty($event['subtitle'])): ?>
            <p class="page-description"><?php echo htmlspecialchars($event['subtitle']); ?></p>
            <?php endif; ?>
            <?php echo $event['detail_html'] ?? ''; ?>
        </div>
        <?php endforeach; ?>
    </div>
    <?php else: ?>
    <div class="calendar-day-number"><?php echo htmlspecialchars($label); ?></div>
    <?php endif; ?>
    <?php
}

function renderCalendarEventButton(string $date, int $index, array $event): void
{
    $detailId = 'calendar-detail-' . md5($date . '-' . $index . '-' . ($event['title'] ?? ''));
    ?>
    <button
        type="button"
        class="calendar-event <?php echo htmlspecialchars($event['class'] ?? ''); ?>"
        data-calendar-title="<?php echo htmlspecialchars($event['title'] ?? '詳細'); ?>"
        data-calendar-detail="<?php echo htmlspecialchars($detailId); ?>"
    >
        <span class="calendar-event-title"><?php echo htmlspecialchars($event['title'] ?? ''); ?></span>
        <?php if (!empty($event['subtitle'])): ?>
        <span class="calendar-event-subtitle"><?php echo htmlspecialchars($event['subtitle']); ?></span>
        <?php endif; ?>
    </button>
    <div id="<?php echo htmlspecialchars($detailId); ?>" class="calendar-detail-source" hidden>
        <?php echo $event['detail_html'] ?? ''; ?>
    </div>
    <?php
}

function renderCalendarTimedEvent(string $date, int $index, array $event, int $startHour, int $hourHeight): void
{
    $startMinutes = calendarTimeToMinutes($event['start_time'] ?? null);
    $endMinutes = calendarTimeToMinutes($event['end_time'] ?? null);
    if ($startMinutes === null || $endMinutes === null || $endMinutes <= $startMinutes) {
        renderCalendarEventButton($date, $index, $event);
        return;
    }

    $top = max(0, (($startMinutes - ($startHour * 60)) / 60) * $hourHeight);
    $height = max(32, (($endMinutes - $startMinutes) / 60) * $hourHeight);
    $detailId = 'calendar-detail-' . md5($date . '-' . $index . '-' . ($event['title'] ?? ''));
    ?>
    <button
        type="button"
        class="calendar-time-event <?php echo htmlspecialchars($event['class'] ?? ''); ?>"
        style="top: <?php echo htmlspecialchars((string) $top); ?>px; height: <?php echo htmlspecialchars((string) $height); ?>px;"
        data-calendar-title="<?php echo htmlspecialchars($event['title'] ?? '詳細'); ?>"
        data-calendar-detail="<?php echo htmlspecialchars($detailId); ?>"
    >
        <span class="calendar-event-title"><?php echo htmlspecialchars($event['title'] ?? ''); ?></span>
        <?php if (!empty($event['subtitle'])): ?>
        <span class="calendar-event-subtitle"><?php echo htmlspecialchars($event['subtitle']); ?></span>
        <?php endif; ?>
    </button>
    <div id="<?php echo htmlspecialchars($detailId); ?>" class="calendar-detail-source" hidden>
        <?php echo $event['detail_html'] ?? ''; ?>
    </div>
    <?php
}
