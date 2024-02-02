<?php

namespace App\Services;

use App\Exceptions\DuplicateFileException;
use Carbon\Carbon;
use App\Models\Event;
use App\Models\Result;
use App\Exceptions\SmallDistanceException;
use App\Exceptions\TimeIsOutOfRangeException;
use App\Exceptions\TimeMissingException;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use App\Exceptions\DuplicityException;
use App\Exceptions\DuplicityTimeException;
use App\Models\TrackPoint;
use App\Models\Registration;
use Illuminate\Support\Facades\Log;
use App\Models\User;
use Polyline;
use Illuminate\Support\Facades\Http;
use GuzzleHttp\Client;
use GuzzleHttp\TransferStats;
use GuzzleHttp\Middleware;
use GuzzleHttp\HandlerStack;
use Illuminate\Support\Facades\DB;
use App\Model\Events;



class ResultService
{

    private $eventDistance;
    private $dateStart;
    private $dateEnd;
    private $dateEventStartTimestamp;
    private $dateEventEndTimestamp;
    private $duplicityCheck;



    public function getStreamFromStrava($request,$activityId = null)
    {
        if($activityId == null)
        {
            $activityId = $request->input('object_id');

            $stravaId = $request->input('owner_id');

            $user = User::select('id', 'strava_access_token', 'strava_refresh_token', 'strava_expires_at')->where('strava_id', $stravaId)->first();

            $userId = $user->id;
        }
        else
        {
            $userId = $request->user()->id;

            $user = User::select('id', 'strava_access_token', 'strava_refresh_token', 'strava_expires_at')->where('id',$userId)->first();
        }

        if ($user->strava_expires_at > time())
        {
            $urlStream = config('strava.stream.url').$activityId.config('strava.stream.params');

            $token = $user->strava_access_token;

            $response = Http::withToken($token)->get($urlStream)->json();

            if ($response)
            {
                $urlActivity = config('strava.activity.url').$activityId.config('strava.activity.params');

                $response += Http::withToken($token)->get($urlActivity)->json();

                $response['user_id'] = $userId;
            }
        }
        else //TOKEN EXPIRED
        {   // URL na Stravu na vymenu tokenu
            $urlToken = config('strava.token.url');
            // parametry pro vymenu tokenu
            $params = config('strava.token.params');
            // doplneni parametru o refresh token
            $params['refresh_token'] = $user->strava_refresh_token;

            $responseToken = Http::post($urlToken,$params);

            $body = $responseToken->body();

            $content = json_decode($body, true);

            $user = new User();

            $token = $user->updateStravaToken($userId,$content);

            $urlStream = config('strava.stream.url').$activityId.config('strava.stream.params');

            $response = Http::withToken($token)->get($urlStream)->json();

            if ($response)
            {
                $urlActivity = config('strava.activity.url').$activityId.config('strava.activity.params');

                $response += Http::withToken($token)->get($urlActivity)->json();

                $response['user_id'] = $userId;
            }
        }

        return $response;

    }




















    /**
     *  ziskani vysledkových dat z GPX souboru
     */
    public function getActivityFinishDataFromGpx($args)
    {
        $request = $args['request'];

        $userId = $request->user()->id;

        $event = Event::where('id', $request->eventId);

        $eventDistance = $event->value('distance');

        $dateEventStartTimestamp = Carbon::createFromFormat('Y-m-d', $event->value('date_start'))->timestamp;

        $dateEventEndTimestamp = Carbon::createFromFormat('Y-m-d', $event->value('date_end'))->timestamp;

        $file = $request->file('gpx_file');

        $xmlObject = simplexml_load_string(trim($file->get()));

        //datum a cas zacatku aktivity z metadat
        $activityDateTime = $xmlObject->metadata->time;

        // pokud není, tak to je spatne a takovy soubor neni mozne prijmout
        if ($activityDateTime == null)
        {
            throw new TimeMissingException();
        }

        //datum aktivity pro dotaz do DB
        $activityDate = date("Y-m-d", strtotime($activityDateTime));

        $distance = 0;

        $trackPointArray = [];

        $lastPointLat = $lastPointLon = $currentPointLat = $currentPointLon = $distance = null;

        $i = 1;
        //TODO ZRUSIT $i a dat to do foreach

        foreach ($xmlObject->trk->trkseg->trkpt as $point)
        {
            // kontrola, jestli je GPX obsahuje elementy time
            if (!isset($point->time)) {
                throw new TimeMissingException();
            }
            //prevedeni casu do Timestampu
            //TODO MUSI SE PREVADET VSECKY CASY?
            $time = $this->iso8601ToTimestamp($point->time);
            //kontrola, jestli je cas v rozsahu zavodu
            if (!$this->isTimeInRange($time, $dateEventStartTimestamp, $dateEventEndTimestamp))
            {
                throw new TimeIsOutOfRangeException('Čas je mimo rozsah akce.');
            }
            // zacatek startu aktivity, teoreticky by se měl shodovat s case v metadatech, ale pro jistotu
            if ($i == 1)
            {
                $startDayTimestamp = $time;
            }

            $lastPointLat = $currentPointLat;

            $lastPointLon = $currentPointLon;

            $currentPointLat = floatval($point['lat']);

            $currentPointLon = floatval($point['lon']);
            //pridavani prvku do pole TrackPointArray
            $trackPointArray[] = [
                'latitude' => $currentPointLat,
                'longitude' => $currentPointLon,
                'time' => $time,
                'user_id' => $userId,
            ];
            //pokud je to prvni, nebo prazdny bod, tak se nic nepocita

            if ($lastPointLat != null)
            {
                $pointDistance = $this->haversineGreatCircleDistance($lastPointLat, $lastPointLon, $currentPointLat, $currentPointLon);

                $distance += $pointDistance;
                //pokud načítaná vzdálenost je větší než délka závodu, tak se vypocita cas a dal se v cyklu, ktery prochazi souborem, nepokracuje
                if ($distance >= $eventDistance)
                {
                    $finishTime = $this->finishTimeCalculation($eventDistance,$distance, $point->time, $startDayTimestamp);

                    break;
                }
            }
            $i++;
        }
        // pokud skončí cyklus a vzdálenost je menší než délka závodu, tak se vyhodí vyjimka
        if ($distance < $eventDistance)
        {
            throw new SmallDistanceException('Vzdálenost je menší než délka tratě.');
        }
        else
        {
            //pokud to je ok, vrací se pole s výsledky
            return [
                'finish_time' => $finishTime['finish_time'],
                'finish_time_sec' => $finishTime['finish_time_sec'],
                'finish_time_date' => $activityDate,
                'average_time_per_km' => $finishTime['average_time_per_km'],
                'track_points' => $trackPointArray,
            ];
        }
    }





    /**
     * automaticke nahravani dat za Stravy
     */
    public function dataStravaProcessing($activityData, $registration)
    {


        $eventDate = Carbon::parse($activityData['start_date'])->format('Y-m-d');

        $events = Event::where('date_start', '<=', $eventDate)
        ->where('date_end', '>=', $eventDate)
        ->orderBy('distance', 'DESC')
        ->get(['id', 'distance']);

        $user = User::where('strava_id', 128967935)->value('id');    //value narozdil od first bere pouze potrebny sloupec
        // dd($user);

        if (!isset($events)) {
            //TODO dopsat vyjimku, ze neexistuje zadny zavod v urcenem casovem obdobi

            dd("neni zadny zavod");
        }


        foreach ($events as $event) {
            // dump( $activityData['distance']);

            if ($activityData['distance'] >= $event['distance']) {


                //dd($event['id']);


                if (isset($registration->registrationExists($event['id'], $user)->id)) {
                    $registrationId = $registration->registrationExists($event['id'], $user)->id;
                    //dd( $registration_id );

                    $trackPoints = [];
                    $coordinates = Polyline::decode($activityData['map']['summary_polyline']);
                    // dd($coordinates);
                    foreach ($coordinates as $coordinate) {
                        $trackPoints[] = [
                            'latitude' => $coordinate[0],
                            'longitude' => $coordinate[1],
                            'user_id' => $user

                        ];
                    }

                    //delka jednotliveho zavodu uvedena v db
                    $this->eventDistance = $event['distance']; //bude lepsi poslat jako parametr, ne?
                    // $finishTime = $this->finishTimeCalculation($trackPoint['time'],$trackPoint['distance'],$startDayTimestamp);
                    $finishTime = $this->finishTimeCalculation($activityData['elapsed_time'], $activityData['distance']);

                    //dd($trackPoints);




                    return [
                        'finish_time' => $finishTime['finish_time'],
                        'finish_time_sec' => $finishTime['finish_time_sec'],
                        'average_time_per_km' => $finishTime['average_time_per_km'],
                        'track_points' => $trackPoints,
                        'registration_id' => $registrationId,
                        'finish_time_date' => $eventDate
                    ];


                } else {

                    // dump('neni prihlasen');
                    //uzivatel neni prihlasen k zavodu, ktery delkove vyhovuje
                }


            } else {
                //dump('zadna trat delkove nevyhovuje');
            }


        }


    }









    /**
     * zatím sloužilo pro simulaci nahrani post pozadavku ze stravy
     */

    public function getActivityFinishDataFromStravaStream($args)
    {

        $request = $args['request'];

        $userId = $request->user()->id;

        $event = Event::where('id', $request->eventId);

        $eventDistance = $event->value('distance');

        $dateEventStartTimestamp = Carbon::createFromFormat('Y-m-d', $event->value('date_start'))->timestamp;

        $dateEventEndTimestamp = Carbon::createFromFormat('Y-m-d', $event->value('date_end'))->timestamp;

        $activityData = $args['activity_data'];

        $startDayTimestamp = strtotime($activityData['start_date_local']);
        //datum aktivity pro dotaz do DB
        $activityDate = date("Y-m-d", $startDayTimestamp);
        //vytvoreni noveho pole se stejnymi paramatry jak GPX soubor
        $activityDataArray = [];

        // vytvoreni pole ve stejne strukture jak GPX soubor
        foreach ($activityData['latlng']['data'] as $key => $val)
        {
            $activityDataArray[] = [
                    'latlng' => $val,
                    'time' => $activityData['time']['data'][$key] + $startDayTimestamp,
                    'distance' => $activityData['distance']['data'][$key],
                    'altitude' => $activityData['altitude']['data'][$key]
                ];

        }

        $trackPointArray = [];

        $lastPointLat = $lastPointLon = $currentPointLat = $currentPointLon = $distance = null;

        foreach($activityDataArray as $point)
        {


            if (!$this->isTimeInRange($point['time'], $dateEventStartTimestamp, $dateEventEndTimestamp))
            {
                throw new TimeIsOutOfRangeException('Čas je mimo rozsah akce.');
            }

            $lastPointLat = $currentPointLat;

            $lastPointLon = $currentPointLon;

            $currentPointLat = floatval($point['latlng'][0]);

            $currentPointLon = floatval($point['latlng'][1]);

            //dump($lastPointLat);

            $trackPointArray[] = [
                'latitude' => $currentPointLat,
                'longitude' => $currentPointLon,
                'time' => $point['time'],
                'user_id' => $userId,
            ];


            if ($lastPointLat != null) {

                $pointDistance = round($this->haversineGreatCircleDistance($lastPointLat, $lastPointLon, $currentPointLat, $currentPointLon), 1);

                $distance += $pointDistance;

                if ($distance >= $eventDistance)
                {
                    $finishTime = $this->finishTimeCalculation($eventDistance,$point['distance'],$point['time'],$startDayTimestamp);

                    return [
                        'finish_time' => $finishTime['finish_time'],
                        'finish_time_sec' => $finishTime['finish_time_sec'],
                        'average_time_per_km' => $finishTime['average_time_per_km'],
                        'track_points' => $trackPointArray,
                      //  'registration_id' => $registrationId,
                        'finish_time_date' => $activityDate,
                    ];
                }
            }
        }

        throw new SmallDistanceException('Vzdálenost je menší než délka tratě.');

    }


    public function getActivityFinishDataFromStravaWebhook($activityData, $registration, $userId)
    {
        //pocatecni cas aktivity v UNIX sekundach
        $startDayTimestamp = strtotime($activityData['start_date_local']);
        //datum aktivity pro dotaz do DB
        $activityDate = date("Y-m-d", $startDayTimestamp);
        //pole pro ulozeni bodu trasy
        $trackPointArray = [];
        //vytvoreni noveho pole se stejnymi paramatry jak GPX soubor
        $activityDataArray = [];
        // vytvoreni pole ve stejne strukture jak GPX soubor
        foreach ($activityData['latlng']['data'] as $key => $val)
        {
            $activityDataArray[] = [
                    'latlng' => $val,
                    'time' => $activityData['time']['data'][$key] + $startDayTimestamp,
                    'distance' => $activityData['distance']['data'][$key],
                    'altitude' => $activityData['altitude']['data'][$key]
                ];
        }
        //vypis zavodů v danném časovém období
        $events = Event::where('date_start', '<=', $activityDate)
        ->where('date_end', '>=', $activityDate)
        ->orderBy('distance', 'DESC')
        ->get(['id', 'distance']);
        //kontrola, jestli v daném časovém období existuje nějaký závod
        if (!isset($event))
        {
            Log::alert('Uzivatel '.$userId.' nahrál aktivitu, ale v daném časovém období neexistuje žádný závod');
        }
        //výpočet celkové vzdálenosti aktivity
        $activityDistance = $this->activityDistanceCalculation($activityDataArray);
        //procházení závodů, jestli délkově odpovídají a jestli je k nim uzivatel prihlasen
        foreach ($events as $key => $event)
        {
            dump($activityDistance.'-'.$event['distance']);
            //kontrola, jestli délka aktivity odpovídá délce nejakeho závodu
            if ($activityDistance >= $event['distance']) {
                //pokud ano, tak kontrola, jestli je uzivatel k nemu prihlasen
                if (isset($registration->registrationExists($event['id'], $userId)->id)) {
                   //pokud ano, tak si vezmeme id registrace uzivatele k zavodu
                    $registrationId = $registration->registrationExists($event['id'], $userId)->id;
                    //prochazeni pole s daty aktivity
                    foreach($activityDataArray as $activityData)
                    {
                        //vytvorime TrackPointArray pro ulozeni do DB
                        $trackPointArray[] = [
                            'latitude' => $activityData['latlng'][0],
                            'longitude' => $activityData['latlng'][1],
                            'time' => $activityData['time'],
                            'altitude' => $activityData['altitude'],
                            'user_id' => $userId,
                        ];


                        dump($activityData['distance'].'-'.$event['distance']);
                        if($activityData['distance'] >= $event['distance'])
                        {
                            dd();
                            $finishTime = $this->finishTimeCalculation($event['distance'],$activityData['distance'],$activityData['time'],$startDayTimestamp);

                            return [
                                'finish_time' => $finishTime['finish_time'],
                                'finish_time_sec' => $finishTime['finish_time_sec'],
                                'average_time_per_km' => $finishTime['average_time_per_km'],
                               'track_points' => $trackPointArray,
                               'registration_id' => $registrationId,
                                'finish_time_date' => $activityDate,
                            ];



                        }
                    }










                    //dump("tady odpovídá i delka a zavodnik je i zaregistrovany pod id ".$registrationId);
                    break;
                } else {

                    //Log::info('Event '.$event['id'].' délkově odpovídá, ale uživatel id $request->user()->id. k nemu není přihlášený');
                    //continue;
                }








            }
            else
            {
                Log::alert('Uzivatel '.$userId.' nahrál aktivitu, ale v daném časovém období neexistuje žádný závod v takové délce');
            }

        }

        dd();


    }











    /**
     * to je nahravani z autodistance upload
     */

    private function nonameYet($userId,$activityId)
    {

        $activityId = $this->removePossibleSlashBehindString($activityId);

        $user = User::select('id','strava_access_token','strava_refresh_token','strava_expires_at')->where('id',$userId)->first();


        if($user->strava_expires_at > time())
        {

            //$url = "https://www.strava.com/api/v3/activities/".$request->input('object_id')."?include_all_efforts=true";

            $url = "https://www.strava.com/api/v3/activities/".$activityId."/streams?keys=time,latlng,altitude&key_by_type=true";




            $token = $user->strava_access_token;
            $response = Http::withToken($token)->get($url)->json();



            if($response)
            {
                $url = "https://www.strava.com/api/v3/activities/".$activityId."?include_all_efforts=false";
                $response += Http::withToken($token)->get($url)->json();
                dd($response);

                //$data = $this->dataProcessing($resultService,$registration,$trackPoint,$event,$response,$user->id);
            }

        }
        else
        {
            $response = Http::post('https://www.strava.com/oauth/token', [
                'client_id' => '117954',
                'client_secret' => 'a56df3b8bb06067ebe76c7d23af8ee8211d11381',
                'refresh_token' => $user->strava_refresh_token,
                'grant_type' => 'refresh_token',
            ]);

            $body = $response->body();
            $content = json_decode($body, true);


            $user1 = User::where('id',$user->id)->first();
            $user1->strava_access_token = $content['access_token'];
            $user1->strava_refresh_token = $content['refresh_token'];
            $user1->strava_expires_at = $content['expires_at'];


            $user1->save();



            $url = "https://www.strava.com/api/v3/activities/".$activityId."?include_all_efforts=true";
            $token = $user->strava_access_token;
            $response = Http::withToken($token)->get($url);
            $data = $content;

            $data = $user1;
            $data .= "tady jsem";



        }

    }





    public function overallDistance($request, $registration)
    {


        $trackPointArray = [];
        $file = $request->file('file');



        $xmlObject = simplexml_load_file(trim($file));



        $lastPointLat = null;
        $lastPointLon = null;
        $currentPointLat = null;
        $currentPointLon = null;
        $distance = 0;

        $originalDateTime = $xmlObject->metadata->time;


        $finishTimeDate = Carbon::parse($originalDateTime)->format('Y-m-d');
        //dd($activityDate);

        $events = Event::where('date_start', '<=', $finishTimeDate)
        ->where('date_end', '>=', $finishTimeDate)
        ->orderBy('distance', 'DESC')
        ->get(['id', 'distance']);

        if (!isset($event)) {
            //TODO dopsat vyjimku, ze neexistuje zadny zavod v urcenem casovem obdobi

        }



        // iteration through gpx
        $i = 1;
        foreach ($xmlObject->trk->trkseg->trkpt as $point) {
            //dump($point);

            $time = $this->iso8601ToTimestamp($point->time);

            if ($i == 1) {

                $startDayTimestamp = $time;
                // dd($startDayTimestamp);
            }





            $lastPointLat = $currentPointLat;
            $lastPointLon = $currentPointLon;
            $currentPointLat = floatval($point['lat']);
            $currentPointLon = floatval($point['lon']);







            if ($lastPointLat != null) {
                $pointDistance = $this->vincentyGreatCircleDistance($lastPointLat, $lastPointLon, $currentPointLat, $currentPointLon);
                $distance += $pointDistance;
            }

            $trackPointArray[] = [
                'distance' => $distance,
                'time' => $point->time,
                'user_id' => $request->user()->id,
                'trkpt' =>
                [
                    'latitude' => $currentPointLat,
                    'longitude' => $currentPointLon,
                    'elevation' => $point->ele
                    ]
                ];



                $trackPointArray1[] = [
                    'latitude' => $currentPointLat,
                    'longitude' => $currentPointLon,
                    //'time' => $time,
                    //'elevation' => $point->ele,
                    'user_id' => $request->user()->id,
                ];

                $i++;
            }

            //dd();




            foreach ($events as $event) {
                if ($distance >= $event['distance']) {

                    if (isset($registration->registrationExists($event['id'], $request->user()->id)->id)) {
                        $registrationId = $registration->registrationExists($event['id'], $request->user()->id)->id;
                        //dd( $registration_id );






                        $this->eventDistance = $event['distance'];

                        foreach ($trackPointArray as $trackPoint) {
                            if ($trackPoint['distance'] >= $event['distance']) {

                                $finishTime = $this->finishTimeCalculation($trackPoint['time'], $trackPoint['distance'], $startDayTimestamp);
                                break;
                            }
                        }


                        //dd($finishTime);



                        return [
                            'finish_time' => $finishTime['finish_time'],
                            'finish_time_sec' => $finishTime['finish_time_sec'],
                            'average_time_per_km' => $finishTime['average_time_per_km'],
                            'track_points' => $trackPointArray1,
                            'registration_id' => $registrationId,
                            'finish_time_date' => $finishTimeDate,
                        ];


                    } else {
                        //dd('neni');

                        Log::info('Event ' . $event['id'] . ' délkově odpovídá, ale uživatel id ' . $request->user()->id . ' k nemu není přihlášený');
                        continue;
                    }

                    break;


                }

            }


        }











    /**
     * vyextrahuje id aktivity z odkazu na strave
    */

    public function getActivityIdFromStravaShareLink($shareLink)
    {

        $lastChar = substr($shareLink, -1);
        if($lastChar == '/')
        {
            $shareLink = substr($shareLink, 0, -1);
        }

        $container = [];
        $history = Middleware::history($container);

        $stack = HandlerStack::create();
        $stack->push($history);

        $client = new Client([
            'handler' => $stack,
            'headers' => [
                'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/58.0.3029.110 Safari/537.3',
            ]
        ]);

        $client->get($shareLink);

        foreach ($container as $transaction) {
            $finalUrl = (string)$transaction['request']->getUri();
        }

        if (preg_match('/\/activities\/(\d+)/', $finalUrl, $matches)) {
            $activityId = $matches[1];
            return $activityId;
        }
        else {
            return false;
        }
}












    private function finishTimeCalculation($eventDistance, $rawActivityDistance, $rawDayTimestamp, $startDayTimestamp = null)
    {
        if ($startDayTimestamp == null) {
            $rawFinishTimeSec = $rawDayTimestamp;
        }
        else {
            $t = new Carbon($rawDayTimestamp);
            $finishDayTimestamp = $t->timestamp;

            $rawFinishTimeSec = $finishDayTimestamp - $startDayTimestamp;
        }

        //dd($eventDistance);
        //dump($rawActivityDistance);


        $finishTime = $this->finishTimeRecountAccordingDistance($eventDistance, $rawActivityDistance, $rawFinishTimeSec);
        //dd($finishTime);

        return [
            'finish_time' => $finishTime['finish_time'],
            'finish_time_sec' => intval(round($finishTime['finish_time_sec'], 0)),
            'average_time_per_km' => $this->averageTimePerKm($eventDistance,$finishTime['finish_time_sec'])
        ];

    }


    private function finishTimeRecountAccordingDistance($eventDistance, $activityDistance, $rawFinishTimeSec)
    {
        //kolik sekund trva 1 metr
        $secPerMeter = $rawFinishTimeSec / $activityDistance;


       //kolik metru za 1 sekundu, nepouzito, ale musel jsem si to zformulovat pro tu svou blbou palici
        $meterPerSec  =   $activityDistance / $rawFinishTimeSec;



        $plusDistance = $activityDistance - $eventDistance;

        $plusSecond = $plusDistance * $secPerMeter;

        $finishTimeSec = intval(round($rawFinishTimeSec - $plusSecond));


        $finishTime = Carbon::createFromTimestamp($finishTimeSec)->format('G:i:s');

        return [
            "finish_time" => $finishTime,
            "finish_time_sec" => $finishTimeSec
        ];

    }


    private function activityDistanceCalculation($activityDataArray)
    {
        $lastPointLat = null;
        $lastPointLon = null;
        $currentPointLat = null;
        $currentPointLon = null;
        $distance = 0;


        foreach ($activityDataArray as $point) {
            $lastPointLat = $currentPointLat;
            $lastPointLon = $currentPointLon;
            $currentPointLat = floatval($point['latlng'][0]);
            $currentPointLon = floatval($point['latlng'][1]);

            if ($lastPointLat != null) {
                $pointDistance = round($this->haversineGreatCircleDistance($lastPointLat, $lastPointLon, $currentPointLat, $currentPointLon), 1);
                $distance += $pointDistance;
            }
        }

        return $distance;
    }




















        private function duplicityCheck($userId, $time)
        {
            $result = new Result();

            $allCheckTimesTogether = [];

            $allCheckTimesFromDb = $result->getDuplicityCheck($userId);


            foreach ($allCheckTimesFromDb as $allCheckTimesArrays) {
                foreach ($allCheckTimesArrays as $checkTimesJson) {
                    $checkTimesArray = json_decode($checkTimesJson);

                    if (is_array($checkTimesArray)) {
                        $allCheckTimesTogether = array_merge($allCheckTimesTogether, $checkTimesArray);
                    }
                }
            }

            if (in_array($this->iso8601ToTimestamp($time), $allCheckTimesTogether)) {
                return false;
            } else {
                return true;
            }

        }








































        private function averageTimePerKm($eventDistance,$finishTimeSec)
        {
            $secondPerKm = round(($finishTimeSec * 1000) / $eventDistance);
            $timeObj = Carbon::createFromTime(0, 0, 0)->addSeconds($secondPerKm);

            return substr($timeObj->format('i:s'), 1);
        }

        function isTimeInRange($time, $dateEventStartTimestamp, $dateEventEndTimestamp)
        {
            if ($time >= $dateEventStartTimestamp && $time <= $dateEventEndTimestamp) {
                return true;
            } else {
                return false;
            }
        }




        private function iso8601ToTimestamp($time)
        {
            $t = new Carbon($time);
            return $t->timestamp;
        }



        /**
        * Calculates the great-circle distance between two points, with
        * the Haversine formula.
        * @param float $latitudeFrom Latitude of start point in [deg decimal]
        * @param float $longitudeFrom Longitude of start point in [deg decimal]
        * @param float $latitudeTo Latitude of target point in [deg decimal]
        * @param float $longitudeTo Longitude of target point in [deg decimal]
        * @param float $earthRadius Mean earth radius in [m]
        * @return float Distance between points in [m] (same as earthRadius)
        */
        private function haversineGreatCircleDistance($latitudeFrom, $longitudeFrom, $latitudeTo, $longitudeTo, $earthRadius = 6371000)
        {

            $latFrom = deg2rad($latitudeFrom);
            $lonFrom = deg2rad($longitudeFrom);
            $latTo = deg2rad($latitudeTo);
            $lonTo = deg2rad($longitudeTo);

            $latDelta = $latTo - $latFrom;
            $lonDelta = $lonTo - $lonFrom;

            $angle = 2 * asin(sqrt(pow(sin($latDelta / 2), 2) +
            cos($latFrom) * cos($latTo) * pow(sin($lonDelta / 2), 2)));
            return $angle * $earthRadius;
        }



        private function vincentyGreatCircleDistance($latitudeFrom, $longitudeFrom, $latitudeTo, $longitudeTo, $earthRadius = 6371000)
        {

            // Konverze stupňů na radiány
            $latFrom = deg2rad($latitudeFrom);
            $lonFrom = deg2rad($longitudeFrom);
            $latTo = deg2rad($latitudeTo);
            $lonTo = deg2rad($longitudeTo);

            // Ellipsoid konstanty
            $a = 6378137; // poloměr
            $b = 6356752.314245; // polární poloměr
            $f = 1 / 298.257223563; // zploštění

            $L = $lonTo - $lonFrom;
            $U1 = atan((1 - $f) * tan($latFrom));
            $U2 = atan((1 - $f) * tan($latTo));

            $sinU1 = sin($U1);
            $cosU1 = cos($U1);
            $sinU2 = sin($U2);
            $cosU2 = cos($U2);

            $lambda = $L;
            $lambdaP = 2 * M_PI;
            $iterLimit = 20;

            while (abs($lambda - $lambdaP) > 1e-12 && --$iterLimit > 0) {
                $sinLambda = sin($lambda);
                $cosLambda = cos($lambda);
                $sinSigma = sqrt(($cosU2 * $sinLambda) * ($cosU2 * $sinLambda) + ($cosU1 * $sinU2 - $sinU1 * $cosU2 * $cosLambda) * ($cosU1 * $sinU2 - $sinU1 * $cosU2 * $cosLambda));
                if ($sinSigma == 0) {
                    return 0; // coincident points
                }

                $cosSigma = $sinU1 * $sinU2 + $cosU1 * $cosU2 * $cosLambda;
                $sigma = atan2($sinSigma, $cosSigma);
                $sinAlpha = $cosU1 * $cosU2 * $sinLambda / $sinSigma;
                $cosSqAlpha = 1 - $sinAlpha * $sinAlpha;
                $cos2SigmaM = $cosSigma - 2 * $sinU1 * $sinU2 / $cosSqAlpha;
                if (is_nan($cos2SigmaM)) {
                    $cos2SigmaM = 0; // equatorial line
                }
                $C = $f / 16 * $cosSqAlpha * (4 + $f * (4 - 3 * $cosSqAlpha));
                $lambdaP = $lambda;
                $lambda = $L + (1 - $C) * $f * $sinAlpha * ($sigma + $C * $sinSigma * ($cos2SigmaM + $C * $cosSigma * (-1 + 2 * $cos2SigmaM * $cos2SigmaM)));
            }

            if ($iterLimit == 0) {
                return -1; // formula failed to converge
            }

            $uSq = $cosSqAlpha * ($a * $a - $b * $b) / ($b * $b);
            $A = 1 + $uSq / 16384 * (4096 + $uSq * (-768 + $uSq * (320 - 175 * $uSq)));
            $B = $uSq / 1024 * (256 + $uSq * (-128 + $uSq * (74 - 47 * $uSq)));
            $deltaSigma = $B * $sinSigma * ($cos2SigmaM + $B / 4 * ($cosSigma * (-1 + 2 * $cos2SigmaM * $cos2SigmaM) - $B / 6 * $cos2SigmaM * (-3 + 4 * $sinSigma * $sinSigma) * (-3 + 4 * $cos2SigmaM * $cos2SigmaM)));

            $s = $b * $A * ($sigma - $deltaSigma);

            return $s; // vrací vzdálenost v metrech
        }


        private function generateRandomNumbers()
        {
            $min = 13;
            $max = 38;
            $randomNumbers = [];

            $randomNumber1 = rand($min, $max);
            $randomNumber2 = rand($min, $max);

            if ($randomNumber1 == $randomNumber2) {
                $this->generateRandomNumbers();
            } else {
                $randomNumbers = [$randomNumber1, $randomNumber2];
                sort($randomNumbers);

                return $randomNumbers;
            }
        }


        public function resultSave($request,$registrationId,$finishTime)
        {
            $result = new Result();

            $result->registration_id = $registrationId;

            $result->finish_time_date = $finishTime['finish_time_date'];

            $result->finish_time = $finishTime['finish_time'];

            $result->average_time_per_km = $finishTime['average_time_per_km'];

            $result->finish_time_sec = $finishTime['finish_time_sec'];

            DB::beginTransaction();

            try
            {
                $result->save();
            }
            catch(QueryException $e)
            {
                return [
                    'error' => 'ERROR_DB',
                    'error_message' => $e->getMessage(),
                ];
            }

            for($i = 0; $i < count($finishTime['track_points']); $i++)
            {
                $finishTime['track_points'][$i]['result_id'] = $result->id;
            }

            $trackPoint = new TrackPoint();

            try{
                $trackPoint::insert($finishTime['track_points']);
                DB::commit();
            }
            catch (UniqueConstraintViolationException $e)
            {
                if($e->errorInfo[1] == 1062)
                {
                    DB::rollback();
                    throw new DuplicityTimeException();
                }
            }

            $r = Result::where('registration_id', $registrationId)
            ->orderBy('finish_time', 'asc')
            ->get();

            $lastId = $result->id;
            foreach($r as $key => $value)
            {
                if($value->id == $lastId)
                 {
                    $rank = $key + 1;
                }

                Result::where('id', $value->id)->update(['finish_time_order' => $key + 1]);
            }

            $event = new Event();

            return [
                'results' =>  Result::selectRaw('id,DATE_FORMAT(finish_time_date,"%e.%c") AS date,finish_time')
                ->where('registration_id', $registrationId)
                ->orderBy('finish_time', 'asc')
                ->get(),
                'event' => $event::find($request->eventId),
                'last_id' => $lastId,
                'rank' => $rank

            ];


        }


    public function getSubdomain($url)
    {
        $parseUrl = parse_url($url);

        $explodeHost = explode('.', $parseUrl['host']);

        return $explodeHost[0];
    }

    public function getActivityId($string)
    {
        $lastChar = substr($string, -1);
        if($lastChar == '/')
        {
            $string = substr($string, 0, -1);
        }


        return substr($string, strrpos($string, '/') + 1);
    }









    }
