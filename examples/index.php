<?php

use League\OAuth2\Client\Token\AccessTokenInterface;

include_once __DIR__ . '/bootstrap.php'; //bootstrap the amocrm app

require_once __DIR__ . '/quickstart.php'; //google mail fetcher

$accessToken = getToken();

$apiClient->setAccessToken($accessToken)
    ->setAccountBaseDomain($accessToken->getValues()['baseDomain'])
    ->onAccessTokenRefresh(
        function (AccessTokenInterface $accessToken, string $baseDomain) {
            saveToken(
                [
                    'accessToken' => $accessToken->getToken(),
                    'refreshToken' => $accessToken->getRefreshToken(),
                    'expires' => $accessToken->getExpires(),
                    'baseDomain' => $baseDomain,
                ]
            );
        }
    );

try {
    //Initialize our variables (maxYear, maxQuar - for sorting)
    [ $maxYear, $maxQuar, $pipelineId, $lastPipeline ] = [false, false, false, false];

    $pipelines = $apiClient->pipelines()->get()->toArray();

    //=======================find latest pipeline and pipeline ID to find Status========
    foreach ($pipelines as $pipeline) {
        [$quar, $year] = explode('-', $pipeline['name']);

        if (mb_strstr($quar, 'Ч') == true) {
            if (!$maxYear || ($maxYear < $year)) {
                $maxYear = $year;
                $maxQuar = false;
                if(!$maxQuar || ($maxQuar < $quar)) {
                    $maxQuar = $quar;
                    $pipelineId = $pipeline['id'];
                    $lastPipeline = $pipeline;
                }
            }
        }
    }

    if (!empty($pipelineId)) {
        echo "Found pipeline { $maxQuar $maxYear }... Continuing.." . PHP_EOL;
    }

    //=============================Find status id (column)=============================
    $statusColumnId = null;
    if (!empty($lastPipeline)) {
        foreach ($lastPipeline['statuses'] as $status) {
            if (mb_strstr($status['name'], 'НЕОБРАБОТАННОЕ') == true) {
                $statusColumnId = $status['id'];
                echo 'Found status { ' . $status['name'] . ' }... Continuing..' . PHP_EOL;
            }
        }
    }

    //============================ get leads ============================
    $filter = new \AmoCRM\Filters\LeadsFilter();
    $filter->setPipelineIds($pipelineId)->setStatuses([$statusColumnId]);
    $filter->setLimit(250);
    $baseRangeFilter = new \AmoCRM\Filters\BaseRangeFilter();
    $baseRangeFilter->setFrom(time() - 3 * 24 * 60 * 60)->setTo(time()); // 3 days from now
    $filter->setCreatedAt($baseRangeFilter);
    $leads = $apiClient->leads()->get($filter, [])->toArray();

    $emails = [];

    foreach ($leads as $lead) {
        $createdAt = $lead['created_at'];
        //If is a unresolved lead
        if (mb_stristr($lead['name'], 'Новое сообщение от ') !== false) {
            if (compareDate($createdAt) === true) {
                array_push($emails, last(explode(' ', $lead['name'])));
            }
        }
    }
    echo '===== 2 days/old unique emails from amoCRM: ======' . PHP_EOL;
    $emails = array_unique($emails);
    print_r($emails);

} catch (\AmoCRM\OAuth2\Client\Provider\AmoCRMException $e) {
    printError($e);
}

function compareDate($dateToCompare) {
    $createdAtDate = date("j F", $dateToCompare);
    $futureDate = date("j F", strtotime($createdAtDate . '+ 2 days'));
    $presentDate = date('j F');

    if (date($futureDate) === date($presentDate)) {
        return true;
    }
    return false;
}

// Emails that answered the welcome message (required for difference)
$googleMails = getGoogleEmails();
echo '===== Unique Google Emails =====' . PHP_EOL;
print_r($googleMails);

$targetEmails = array_diff($emails, $googleMails);
echo '===== End result =====' . PHP_EOL;
print_r($targetEmails);

function sendMail($targetEmails) {
    echo '===== Preparing to send emails =====' . PHP_EOL;
    // Create the Transport
    $transport = (new Swift_SmtpTransport('smtp.gmail.com', 465, 'ssl'))
        ->setAuthMode('login')
        ->setUsername('foo@foobar.com')
        ->setPassword('foobarPassword')
    ;


// Create the Mailer using your created Transport
    $mailer = new Swift_Mailer($transport);

// Create a message
    $message = (new Swift_Message('HelloWorld'))
    ->setFrom(['foobar@gmail.com' => 'HelloWorld'])
    ->setTo(['foobar@foobar.com'])
    ->setBcc($targetEmails)
    ->setBody('Lorem ipsum');

// Send the message
    $result = $mailer->send($message);

    if (!empty($result)) {
        echo 'Emails were sent!' . PHP_EOL;
        return true;
    } else {
        echo 'Something went wrong... Emails were not sent' . PHP_EOL;
        return false;
    }
}





