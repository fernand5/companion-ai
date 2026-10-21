<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Middleware\EnsureApplicationKeyIsUsable;
use App\Http\Requests\ProgressionProposalRequest;
use App\Services\Fitness\Progression\AdaptivePlanningService;
use App\Services\Fitness\Progression\ProgressionApprovalException;
use App\Services\Fitness\Progression\ProgressionApprovalService;
use App\Services\Fitness\Progression\ProgressionUnavailableException;
use App\Support\ApplicationKey;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ProgressionController extends Controller
{
    public function __construct(
        private readonly ProgressionApprovalService $approvals,
        private readonly AdaptivePlanningService $planning,
    ) {}

    /**
     * The authenticated user's own recommendations, in the context of the upcoming sessions they would apply
     * to. Calculated by the application; changes nothing.
     */
    public function index(Request $request): JsonResponse
    {
        try {
            return response()->json($this->planning->prepare($request->user()));
        } catch (ProgressionUnavailableException) {
            return $this->unavailable($request);
        }
    }

    /** Approve one sealed proposal the server issued. The only effect is the one load it describes. */
    public function approve(ProgressionProposalRequest $request): JsonResponse
    {
        try {
            return response()->json($this->approvals->approve($request->user(), $request->validated('proposal')));
        } catch (ProgressionApprovalException $e) {
            return response()->json(['code' => $e->reason, 'message' => $e->getMessage()], $e->status);
        } catch (ProgressionUnavailableException) {
            return $this->unavailable($request);
        }
    }

    /** "Keep current target": remembered for this exact recommendation. The plan is not changed. */
    public function reject(ProgressionProposalRequest $request): JsonResponse
    {
        try {
            $this->approvals->decline($request->user(), $request->validated('proposal'));
        } catch (ProgressionApprovalException $e) {
            return response()->json(['code' => $e->reason, 'message' => $e->getMessage()], $e->status);
        } catch (ProgressionUnavailableException) {
            return $this->unavailable($request);
        }

        return response()->json(['kept' => true]);
    }

    /** The route middleware normally answers first; this is the same answer if sealing fails anyway. */
    private function unavailable(Request $request): JsonResponse
    {
        ApplicationKey::reportUnusable($request->path());

        return EnsureApplicationKeyIsUsable::unavailable();
    }
}
