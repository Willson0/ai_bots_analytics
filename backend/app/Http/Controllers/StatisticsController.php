<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;

class StatisticsController extends Controller
{
    public function get (Request $request) {
        // добавить разрешенных пользователей (через мидлвейр желательно)
        $answer = [];
        $answer["users"] = [];
        $answer["users"]["active"] = "";
    }
}
