<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\UpdateFitnessProfileRequest;
use App\Http\Resources\FitnessProfileResource;
use Illuminate\Http\Request;

class ProfileController extends Controller
{
    public function show(Request $request)
    {
        $profile = $request->user()->fitnessProfile;

        return $profile ? new FitnessProfileResource($profile) : response()->noContent();
    }

    public function update(UpdateFitnessProfileRequest $request)
    {
        $profile = $request->user()->fitnessProfile()->updateOrCreate(
            ['user_id' => $request->user()->id],
            $request->validated(),
        );

        return new FitnessProfileResource($profile);
    }
}
