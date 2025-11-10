<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\EventResource;
use App\Models\Event;
use Illuminate\Http\JsonResponse;

class EventController extends Controller
{
    public function show(Event $event): JsonResponse
    {
        $data = (new EventResource($event))->toArray(request());
        return response()->json($data);
    }
}
