<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class JambPinController extends Controller
{
    public function index()
    {
        return view('jamb-pin.index', [
            'user' => Auth::user(),
        ]);
    }
    
    public function purchase(Request $request)
    {
        // Handle JAMB PIN purchase logic
        return redirect()->route('jamb-pin.index')
            ->with('success', 'JAMB PIN purchased successfully!');
    }

    

public function verifyProfile(Request $request)
    {
        $request->validate([
            'profile_id' => 'required|string|size:10|regex:/^[A-Z0-9]+$/',
            'exam_type' => 'required|in:utme,de'
        ]);

        try {
            // Log the request (without API key for security)
            Log::info('JAMB Profile Verification Request', [
                'profile_id' => $request->profile_id,
                'exam_type' => $request->exam_type,
                'user_id' => auth()->id()
            ]);

            // Make secure API call from server
            $response = Http::timeout(30)->get('https://www.nellobytesystems.com/APIVerifyJAMB.asp', [
                'UserID' => env('CLUBKONNECT_CLIENT_ID'),
            'APIKey' => env('CLUBKONNECT_API_KEY'),
                'ExamType' => $request->exam_type,
                'ProfileID' => $request->profile_id
            ]);

            // Check if request was successful
            if ($response->failed()) {
                Log::error('JAMB Verification API Failed', [
                    'status' => $response->status(),
                    'body' => $response->body()
                ]);
                
                return response()->json([
                    'success' => false,
                    'message' => 'Verification service unavailable'
                ], 503);
            }

            $data = $response->json();
            
            Log::info('JAMB Verification Response', [
                'profile_id' => $request->profile_id,
                'response' => $data
            ]);

            return response()->json([
                'success' => true,
                'data' => $data
            ]);

        } catch (\Exception $e) {
            Log::error('JAMB Verification Error', [
                'error' => $e->getMessage(),
                'profile_id' => $request->profile_id
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Verification failed. Please try again.'
            ], 500);
        }
    }

    public function purchasePin(Request $request)
    {
        $request->validate([
            'profile_id' => 'required|string|size:10|regex:/^[A-Z0-9]+$/',
            'exam_type' => 'required|in:utme,de',
            'phone' => 'required|string|size:11|regex:/^[0-9]+$/',
            'request_id' => 'required|string'
        ]);

        try {
            // Check wallet balance
            $user = auth()->user();
            $amount = $this->getExamPrice($request->exam_type);
            
            if ($user->wallet_balance < $amount) {
                return response()->json([
                    'success' => false,
                    'message' => 'Insufficient wallet balance'
                ], 400);
            }

            // Generate callback URL
            $callbackUrl = route('jamb.callback');
            
            // Make purchase API call
            $response = Http::timeout(60)->get('https://www.nellobytesystems.com/APIJAMBV1.asp', [
                'UserID' => config('services.nellobytes.user_id'),
                'APIKey' => config('services.nellobytes.api_key'),
                'ExamType' => $request->exam_type,
                'PhoneNo' => $request->phone,
                'RequestID' => $request->request_id,
                'CallBackURL' => $callbackUrl
            ]);

            $data = $response->json();
            
            if ($data['statuscode'] === '200' || $data['status'] === 'ORDER_COMPLETED') {
                // Deduct from wallet
                $user->decrement('wallet_balance', $amount);
                
                // Save transaction
                Transaction::create([
                    'user_id' => $user->id,
                    'type' => 'jamb',
                    'reference' => $data['orderid'],
                    'amount' => $amount,
                    'status' => 'success',
                    'meta' => json_encode($data)
                ]);

                return response()->json([
                    'success' => true,
                    'message' => 'Purchase successful',
                    'data' => $data
                ]);
            } else {
                return response()->json([
                    'success' => false,
                    'message' => $data['remark'] ?? 'Purchase failed'
                ], 400);
            }

        } catch (\Exception $e) {
            Log::error('JAMB Purchase Error', [
                'error' => $e->getMessage(),
                'user_id' => auth()->id()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Purchase failed. Please try again.'
            ], 500);
        }
    }

    private function getExamPrice($examType)
    {
        return $examType === 'utme' ? 6200 : 15700;
    }
}