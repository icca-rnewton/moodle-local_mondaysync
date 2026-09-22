<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

/**
 * Distinguishes a genuine Monday.com API-availability failure from an
 * ordinary board/item-specific error.
 *
 * @package    local_mondaysync
 * @copyright  2026 Inns of Court College of Advocacy (Part of COIC)
 * @license    http://www.gnu.org/licenses/GPL-3.0 GNU GPL v3 or later
 */

namespace local_mondaysync;

defined('MOODLE_INTERNAL') || die();

/**
 * Thrown specifically for Monday.com API-availability-level failures -
 * connection failures, HTTP 429 (rate limited), HTTP 5xx (server error) -
 * as opposed to an ordinary GraphQL-level error for one specific query
 * (e.g. "board not found"), which is far more likely to be a genuine
 * board/item-specific configuration issue rather than the API itself
 * being unavailable.
 *
 * 2026 addition (external review, item 7): previously every kind of
 * Monday.com failure was logged and swallowed identically, meaning the
 * scheduled task always completed "successfully" from Moodle's own
 * perspective - even when the API was completely down or every request
 * was being rate-limited. That prevented Moodle's own task-failure/
 * backoff behaviour from ever kicking in during a genuine outage. This
 * exception type is deliberately allowed to propagate all the way out of
 * sync_manager::run() rather than being caught and logged like an
 * ordinary per-board issue, so the scheduled task genuinely fails and
 * Moodle's own retry/backoff handling applies.
 */
class api_unavailable_exception extends \moodle_exception {
}