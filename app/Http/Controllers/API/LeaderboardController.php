<?php

namespace App\Http\Controllers\API;

use App\Models\Option;
use App\Models\Prediction;
use Illuminate\Http\Request;
use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Cache;

class LeaderboardController extends Controller
{

    public function getFixtureResult($fixture)
    {
        $cacheData = Cache::get('leaderboard_fixtures__data__cache');
        if ($cacheData) {
            $fixtures = $cacheData;
        } else {
            $fixtures = Http::withHeaders([
                'x-rapidapi-host' => 'fantasy-premier-league3.p.rapidapi.com',
                'x-rapidapi-key' => 'abe4621a9bmshbc1c9a211f870d6p157512jsnd3bbdf64de8b'
            ])->get(config('url.fixtures'), [
                'gw' => $fixture->fixture_event,
            ])->json();
            Cache::put('leaderboard_fixtures__data__cache', $fixtures, now()->addSeconds(20));
        }

        $fixture_collection = collect($fixtures);
        $filtered = $fixture_collection->filter(function($item) use ($fixture){
            return $item["finished"] === true && $item["id"] === (int) $fixture->fixture_id;
        })->values();


        if(count($filtered) > 0){
            return [
                "team_a_score" =>  $filtered[0]['team_a_score'],
                "team_h_score" => $filtered[0]['team_h_score']
            ];
        }else{
            return [
                "team_a_score" => null,
                "team_h_score" => null
            ];
        }

    }

    public function __invoke(Request $request)
    {
        $arrayData = [];
        $gameweek = $request->input('gw');
        $of_user = $request->input('user');

        if($of_user){
            $predictions = Prediction::with('user')->where('fixture_event', $gameweek)->where('user_id',$of_user)->get();
        }else{
            $predictions = Prediction::with('user')->where('fixture_event', $gameweek)->get();
        }


        $options = Option::first();
        $cacheData = Cache::get('leaderboard_fixtures__data__cache');
        if ($cacheData) {
            $fixtures = $cacheData;
        } else {
            $response =  Http::withHeaders([
                'x-rapidapi-host' => 'fantasy-premier-league3.p.rapidapi.com',
                'x-rapidapi-key' => 'abe4621a9bmshbc1c9a211f870d6p157512jsnd3bbdf64de8b'
            ])->get('https://fantasy-premier-league3.p.rapidapi.com/fixtures');
            $fixtures = $response->json();
            Cache::put('leaderboard_fixtures__data__cache', $fixtures, now()->addMinutes(3));
        }


        foreach ($predictions as $prediction) {

            $total_pts = 0;
            $point_logs = [];
            $home_team_predict = $prediction->team_h_goal['value'];
            $away_team_predict = $prediction->team_a_goal['value'];
            $fixture_result = $this->getFixtureResult($prediction);

            if($fixture_result['team_h_score'] === null && $fixture_result['team_a_score'] === null){
                $prediction['total_pts'] = 0;
            }else{
                $final_result = "";
                $predict_result = "";

                $home_team_predict = $prediction->team_h_goal['value'];
                $away_team_predict = $prediction->team_a_goal['value'];
                $home_team_score = $fixture_result['team_h_score'];
                $away_team_score = $fixture_result['team_a_score'];
                if ($home_team_score > $away_team_score) {
                    $final_result = "home_team_win";
                }else if ($home_team_score < $away_team_score) {
                    $final_result = "home_team_lose";
                }else if ($home_team_score == $away_team_score) {
                    $final_result = "draw";
                }

                if ($home_team_predict > $away_team_predict) {
                    $predict_result = "home_team_win";
                }else if ($home_team_predict < $away_team_predict) {
                    $predict_result = "home_team_lose";
                }else if ($home_team_predict == $away_team_predict) {
                    $predict_result = "draw";
                }


                if ($final_result === $predict_result) {
                    // echo "#same = " .  $options->win_lose_draw_pts;
                    array_push($point_logs , ['win_lose' => $options->win_lose_draw_pts] );
                    $total_pts = $total_pts + $options->win_lose_draw_pts;
                }

                // calculate goal different pts

                $goal_different = abs($home_team_score - $away_team_score);
                $goal_different_predict = abs($home_team_predict - $away_team_predict);

                if ($goal_different === $goal_different_predict) {
                    // echo "#dff = " .  $options->goal_difference_pts;
                    array_push($point_logs , ['goal_different' => $options->goal_difference_pts] );
                    $total_pts = $total_pts +  $options->goal_difference_pts;
                }

                // calculate team goal pts
                if ($home_team_predict === $home_team_score) {
                    // echo "#home = " .  $options->home_goals_pts;
                    array_push($point_logs , ['home_team' => $options->home_goals_pts] );
                    $total_pts = $total_pts +  $options->home_goals_pts;
                }

                if ($away_team_predict === $away_team_score) {
                    // echo "#away = " .  $options->away_goals_pts;
                    array_push($point_logs , ['away_team' => $options->away_goals_pts] );
                    $total_pts = $total_pts +  $options->away_goals_pts;
                }

                // two x booster pts

                if ($prediction->twox_booster === 1) {
                    // echo "#before boosted  bx2= " . $total_pts;
                    // echo "#boosted  x= " .  $options->twox_booster_pts;
                    array_push($point_logs , ['before_boost' => $total_pts] );
                    array_push($point_logs , ['after_boost' => $total_pts * $options->twox_booster_pts] );
                    $total_pts = $total_pts * $options->twox_booster_pts;
                }

                $prediction['total_pts'] = $total_pts;
                $prediction['point_logs'] = $point_logs;

                array_push($arrayData, $prediction);
            }

        }



        $result = array();
        foreach ($arrayData as $k => $v) {
            $id = $v['user']['id'];
            $result[$id]['pts'][] = $v['total_pts'];
            $result[$id]['point_logs'][$v['fixture_id']] = $v['point_logs'];
            $result[$id]['user'] = $v['user'];
        }


        $new = array();


        foreach ($result as $key => $value) {
            $new[] = array('user' => $value['user'], 'total_pts' => array_sum($value['pts']) , 'point_logs' => $value['point_logs']);
        }




        $collectData = collect($new);
        $sortedData = $collectData->sortByDesc('total_pts');
        return response()->json([
            'success' => true,
            'flag' => 'leaderboard',
            'message' => 'Get Leaderboard List',
            'data' => $sortedData->values()->filter(function($value){
                return $value['total_pts'] > 0;
            })
        ], 200);
    }
}
