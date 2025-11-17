<?php

namespace Monday\Core\Modules\Monday;

use Monday\Core\API;
use Monday\Core\ClockifyHelper;
use Monday\Core\MondayHelper;

class Model
{


    public static function createClockifyEntry()
    {

        $data = API::$request;
        $board_id = !empty($data['board']) ? $data['board'] : null;
        if (empty($board_id)) {
            API::setResult(false, 'Invalid board id');
        }

        ClockifyHelper::addTimeEntryInClockify($board_id, $data['ticket_id'], $data['description']);

    }

    public static function creationTrigger()
    {
		logError($_POST);
        $event = API::$request['event'] ?? null;
		if (empty($event)) {
			API::setResult(false, 'Invalid request.');
		}
        
        // Maximum number of retries
        $maxRetries = 3;
        // Delay between retries in seconds
        $delaySeconds = 2;
        
        for ($attempt = 1; $attempt <= $maxRetries; $attempt++) {
            if ($attempt > 1) {
//                logError("Attempt $attempt of $maxRetries - Waiting for board relation...");
                sleep($delaySeconds);
            }

            $query = '
            query($ids: [ID!]) {
                  items(ids: $ids) {
                     column_values {
                        id
                        type
                        value
                    }
                 }
            }
            ';

            $vars = [
                'ids' => [$event['pulseId']],
            ];

            $response = json_decode(MondayHelper::curlRequestMonday($query, $vars), true);

            $item = $response['data']['items'][0];
            if (empty($item)) {
                logError('No item found for automation trigger.');
                continue;
            }

            // Find the board relation column
            $boardRelationValue = null;
            foreach ($item['column_values'] as $column) {
                if ($column['type'] === 'board_relation' && $column['id'] === 'board_relation0__1') {
                    $boardRelationValue = $column['value'];
                    break;
                }
            }

            if (!empty($boardRelationValue)) {
				logError('Found relations');
                // We found the value, proceed with the rest of the logic
                $value = json_decode($boardRelationValue, true);
                $pulse_id = $value['linkedPulseIds'][0]['linkedPulseId'];
                
                if (!empty($pulse_id)) {
                    // Use the linked pulse ID for Clockify instead of the original pulse ID
                    $clockifyResult = ClockifyHelper::addTimeEntryInClockify($event['boardId'], $pulse_id, $event['pulseName']);
                    logError('Clockify result: ' . $clockifyResult);
                    
                    return; // Exit the function successfully
                }
            } else {
				logError('Could not find relation, ticket made in production?');
			}
            
//            logError("Attempt $attempt: Board relation not found yet.");
        }

        // If we get here, we've exhausted all retries
//        logError('Failed to get board relation after ' . $maxRetries . ' attempts.');
        die();
    }


    public static function updateTrigger()
    {

        $rawData = file_get_contents("php://input");
        $data = json_decode($rawData, true);
//


        $update = $data['event']['body'];


        if ($data) {

            $mondayId = json_encode($data['event']['pulseId']);
            $boardId = json_encode($data['event']['boardId']);

            $response = MondayHelper::getConnectedItems($mondayId, $update, $boardId);

            if ($response) {
                $linkedItems = $response->data->items[0]->linked_items;
                foreach ($linkedItems as $linkedItem) {

                    MondayHelper::addNotesToTicket($linkedItem->id, $update);
                }
            } else {
                echo 'no response';
//                logError(json_encode($data['event']['textBody'], JSON_PRETTY_PRINT));
            }
        } else {
            die("no input");
        }


        // // Set response code
        http_response_code(200);

        // // Send the response
        header('Content-Type: application/json');

        // MondayHelper want's to receive the same key and value as security
        echo $rawData;

        die();
    }
}