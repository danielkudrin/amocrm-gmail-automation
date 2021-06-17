<?php

require __DIR__ . '/../vendor/autoload.php';

if (php_sapi_name() != 'cli') {
    throw new Exception('GMAIL application must be run on the command line.');
}

/**
 * Returns an authorized API client.
 * @return Google_Client the authorized client object
 */
function getClient()
{
    $client = new Google_Client();
    $client->setApplicationName('Gmail API PHP Quickstart');
    $client->setScopes(Google_Service_Gmail::GMAIL_READONLY);
    $client->setAuthConfig('credentials.json');
    $client->setAccessType('offline');
    $client->setPrompt('select_account consent');

    // Load previously authorized token from a file, if it exists.
    // The file token.json stores the user's access and refresh tokens, and is
    // created automatically when the authorization flow completes for the first
    // time.
    $tokenPath = 'token.json';
    if (file_exists($tokenPath)) {
        $accessToken = json_decode(file_get_contents($tokenPath), true);
        $client->setAccessToken($accessToken);
    }

    // If there is no previous token or it's expired.
    if ($client->isAccessTokenExpired()) {
        // Refresh the token if possible, else fetch a new one.
        if ($client->getRefreshToken()) {
            $client->fetchAccessTokenWithRefreshToken($client->getRefreshToken());
        } else {
            // Request authorization from the user.
            $authUrl = $client->createAuthUrl();
            printf("Open the following link in your browser:\n%s\n", $authUrl);
            print 'Enter verification code: ';
            $authCode = trim(fgets(STDIN));

            // Exchange authorization code for an access token.
            $accessToken = $client->fetchAccessTokenWithAuthCode($authCode);
            $client->setAccessToken($accessToken);

            // Check to see if there was an error.
            if (array_key_exists('error', $accessToken)) {
                throw new Exception(join(', ', $accessToken));
            }
        }
        // Save the token to a file.
        if (!file_exists(dirname($tokenPath))) {
            mkdir(dirname($tokenPath), 0700, true);
        }
        file_put_contents($tokenPath, json_encode($client->getAccessToken()));
    }
    return $client;
}

function getHeader($headers, $name) {
    foreach($headers as $header) {
        if($header['name'] == $name) {
            return $header['value'];
        }
    }
}


// Get email from a 'John Doe <johndoe@example.com>'
function parseEmailString($string)
{
    $nonParsed = mailparse_rfc822_parse_addresses($string);
    foreach ($nonParsed as $parsed) {
        if ($parsed['address']) {
            $parsedEmail = $parsed['address'];
            if(filter_var($parsedEmail, FILTER_VALIDATE_EMAIL)) {
                return $parsedEmail;
            }
            else {
                return false;
            }
        }
    }

}

// Get necessary key => value pairs if subject equals given string
function sortBatchedResults($batchedResults)
{
    $accumulatedMessages = [];

    foreach ($batchedResults as $batchedResult) {
        if ($parsedMail = parseEmailString(getHeader($batchedResult['payload']['headers'], 'From'))) {
            array_push($accumulatedMessages, $parsedMail);
        }
        if ($parsedMail = parseEmailString(getHeader($batchedResult['payload']['headers'], 'Reply-To'))) {
            array_push($accumulatedMessages, $parsedMail);
        }
    }

    return $accumulatedMessages;
}


function queryGmailIteration($client, $service, $iterationCount, $nextPageToken = null, $gmailEmails = [])
{
    if ($iterationCount > 0) {
        $optParams = [];
        $optParams['maxResults'] = 100; // Return Only 100 Messages (max)
//            $optParams['labelIds'] = 'ответа-не-требует'; // Only show messages in Inbox
        $daysFromNow = time() - 10 * 24 * 60 * 60;
        $optParams['q'] = "label:ответили after:$daysFromNow";
        if (!empty($nextPageToken)) {
            $optParams['pageToken'] = $nextPageToken;
        }
        $results = $service->users_messages->listUsersMessages('me', $optParams); //Non-batched query
        $nextPageToken = $results['nextPageToken'];

        $batch = new Google_Http_Batch($client, false, null, 'batch/gmail/v1'); // Hardcoded batch client path from issues https://github.com/googleapis/google-api-php-client/issues/2052
        //Queries below this line are batched
        $client->setUseBatch(true);

        foreach ($results as $mail) {
            $message = $service->users_messages->get('me', $mail['id'], ['format' => 'metadata', 'metadataHeaders' => ['from', 'subject', 'reply-to']]);
            $batch->add($message, "mail-" . $mail['id']);
        }

        $batchedResults = $batch->execute();

        $sortedBatchedResults = sortBatchedResults($batchedResults);

        $gmailEmails = array_merge_recursive($gmailEmails, $sortedBatchedResults);

        $iterationCount--;
        $client->setUseBatch(false);
        echo "iteration $iterationCount (100 values per iteration)" . PHP_EOL;

        return queryGmailIteration($client, $service, $iterationCount, $nextPageToken, $gmailEmails);
    } else {
        return $gmailEmails;
    }
}

function queryGmail($client, $service, $maxResults)
{
    $iterationCount = $maxResults / 100;

    $queryResult = queryGmailIteration($client, $service, $iterationCount);

    return $queryResult;
}


function getGoogleEmails()
{
    try {
        echo '=========== trying to connect to gmail ============' . PHP_EOL;
        // Get the API client and construct the service object.
        $client = getClient();
        $service = new Google_Service_Gmail($client);

        $maxResults = 500; //Configure gmail results output - max 100 per iteration
        $gmailEmails = array_unique(queryGmail($client, $service, $maxResults)); // Returned emails


        return $gmailEmails;
    } catch (Exception $e) {
        print($e->getMessage());
        return 0;
    }
}
