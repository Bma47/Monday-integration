<?php

namespace Monday\Core\Modules\Monday\Classes;

use Exception;
use Monday\Core\ClockifyHelper;
use Monday\Core\DB;
use Monday\Core\Http;
use PDOException;

class MondayTimeTracker

{

    public static function processColumnChange() {
//        logError('==================START PROCESS COLUMN CHANGE==================');

        $event = $_POST['event'] ?? null;
        if (!$event) {
//            logError('Event not found: ' . json_encode($_POST, JSON_PRETTY_PRINT));
            die('Event not found,a canceling...');
        }

//        logError('Value: ' . $event['value']['label']['text']);
        if (!isset($event['value']['label']['text'])) {
            die();
        }

        switch(strtolower($event['value']['label']['text'] ?? '')) {
            case "test failed":
//                logError('FAILED');

                $current_ticket_entry = db::select('SELECT * FROM `ticket_delivery` WHERE `ticket_id` = :pid', ['pid' => $event['pulseId']], false);

                if (empty($current_ticket_entry)) {
                    db::insert('ticket_delivery', [
                        'ticket_id' => $event['pulseId'],
                        'board_id' => $event['boardId'],
                        'description' => $event['pulseName'],
                        'time_completed' => null
                    ]);
                }

                db::update('ticket_delivery', ['times_failed' => $current_ticket_entry['times_failed'] ? $current_ticket_entry['times_failed'] + 1 : 1], ['ticket_id' => $event['pulseId']]);
                break;
            case "klaar":
//                logError('KLAAR');

                $current_ticket_entry = db::select('SELECT * FROM `ticket_delivery` WHERE `ticket_id` = :pid', ['pid' => $event['pulseId']], false);

                $clockifyEntries = ClockifyHelper::getClockifyEntries($event['boardId'], $event['pulseId']);

                $time_spent = 0;
                foreach($clockifyEntries['totals'] as $total) {
                    $time_spent += $total['totalTime'] / 60 / 60;
                }

                $users = [];
                $totalUsersTime = 0; // Initialize total users time

                foreach($clockifyEntries['timeentries'] as $entry) {
                    $username = $entry['userName'];
                    $userHours = $entry['timeInterval']['duration'] / 60 / 60;

                    if (isset($users[$username])) {
                        $users[$username] += $userHours;
                    } else {
                        $users[$username] = $userHours;
                    }

                    $totalUsersTime += $userHours; // Accumulate the user time.
                }

                // Round total user time after summing.
                $totalUsersTime = round($totalUsersTime, 2);

                // Round $time_spent as well.
                $time_spent = round($time_spent, 2);

                // Format for display
                foreach ($users as $username => $hours) {
                    echo $username . ": " . number_format($hours, 2) . "\n";
                }

//                logError($clockifyEntries);

                $within_time = $current_ticket_entry['estimated_time_min'] < $time_spent && $time_spent < $current_ticket_entry['estimated_time_max'] ? 1 : 0;

                db::update('ticket_delivery', [
                    'time_completed' => date('Y-m-d H:i:s'),
                    'total_time_clocked' => $time_spent,
                    'within_time' => (int)$within_time,
                    'clockify_entries' => json_encode(array_map(function($hours) { return round($hours, 2); }, $users), JSON_PRETTY_PRINT)
                ], ['ticket_id' => $event['pulseId']]);

                break;
            default:
                if (strtolower($event['columnTitle']) === 'impact score') {
                    $times = explode('-', $event['value']['label']['text']);
                    foreach($times as $k => $time) {
                        $times[$k] = preg_replace('/[^0-9]/', '', $time);
                    }

                    logError($times);

                    db::update('ticket_delivery', [
                        'estimated_time_min' => $times[0] ?? 0,
                        'estimated_time_max' => empty($times[1]) ? $times[0] : $times[1],
                    ], ['ticket_id' => $event['pulseId']]);
                }
                break;
        }

//        logError('Event found: ' . json_encode($_POST, JSON_PRETTY_PRINT));

//        logError('==================END PROCESS COLUMN CHANGE==================');
    }

    public static function processCreateItem() {
//        logError('==================START PROCESS ITEM==================');

        $event = $_POST['event'] ?? null;
        if (!$event) {
//            logError('Event not found: ' . json_encode($_POST, JSON_PRETTY_PRINT));
            die('Event not found, canceling...');
        }

        try {
            $inserted = db::insert('ticket_delivery', [
                'ticket_id' => $event['pulseId'],
                'board_id' => $event['boardId'],
                'description' => $event['pulseName'],
                'time_completed' => null
            ]);

			ClockifyHelper::addTimeEntryInClockify($event['boardId'], $event['pulseId'], $event['pulseName']);

			if (!empty($inserted)) {
//                logError('Inserted data properly.');
            } else {
//                logError('Failed to insert data.');
            }
        } catch(Exception|PDOException $e) {
            logError($e->getMessage());
        }

//        logError('==================END PROCESS ITEM==================');
    }

}