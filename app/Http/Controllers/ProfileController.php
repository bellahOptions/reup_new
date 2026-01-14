<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

class ProfileController extends Controller
{
    public function index()
    {
        $user = auth()->user();
        
        // Get Nigerian states
        $states = [
            'Abia', 'Adamawa', 'Akwa Ibom', 'Anambra', 'Bauchi', 'Bayelsa', 'Benue', 'Borno', 
            'Cross River', 'Delta', 'Ebonyi', 'Edo', 'Ekiti', 'Enugu', 'FCT', 'Gombe', 'Imo', 
            'Jigawa', 'Kaduna', 'Kano', 'Katsina', 'Kebbi', 'Kogi', 'Kwara', 'Lagos', 'Nasarawa', 
            'Niger', 'Ogun', 'Ondo', 'Osun', 'Oyo', 'Plateau', 'Rivers', 'Sokoto', 'Taraba', 'Yobe', 'Zamfara'
        ];
        
        return view('profile.index', [
            'user' => $user,
            'states' => $states,
        ]);
    }

    public function update(Request $request)
    {
        $user = auth()->user();
        
        // Clean phone numbers before validation
        $request->merge([
            'phone' => preg_replace('/\D/', '', $request->phone),
            'whatsapp' => preg_replace('/\D/', '', $request->whatsapp),
        ]);
        
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255', Rule::unique('users')->ignore($user->id)],
            'phone' => ['nullable', 'string', 'regex:/^0[7-9][0-9]{9}$/', Rule::unique('users')->ignore($user->id)],
            'whatsapp' => ['nullable', 'string', 'regex:/^0[7-9][0-9]{9}$/'],
            'birthday' => ['nullable', 'date', 'before:-18 years'],
            'gender' => ['nullable', 'in:male,female,other'],
            'address' => ['nullable', 'string', 'max:500'],
            'state' => ['nullable', 'string'],
            'city' => ['nullable', 'string'],
            'profile_picture' => ['nullable', 'image', 'mimes:jpeg,png,jpg,gif', 'max:2048'],
        ], [
            'phone.regex' => 'Please enter a valid Nigerian phone number (e.g., 08012345678)',
            'whatsapp.regex' => 'Please enter a valid Nigerian phone number for WhatsApp',
            'birthday.before' => 'You must be at least 18 years old',
        ]);

        // Handle profile picture upload (SIMPLIFIED - without Intervention Image)
        if ($request->hasFile('profile_picture')) {
            $image = $request->file('profile_picture');
            $filename = 'profile-' . $user->id . '-' . time() . '.' . $image->getClientOriginalExtension();
            
            // Store the image (no resizing)
            $path = $image->storeAs('profiles', $filename, 'public');
            
            // Delete old profile picture if exists
            if ($user->profile_picture) {
                Storage::disk('public')->delete('profiles/' . basename($user->profile_picture));
            }
            
            $validated['profile_picture'] = '/storage/profiles/' . $filename;
        }

        // Update user
        $user->update($validated);

        // Check if profile is now completed
        if (!$user->profile_completed && $user->profile_completion_percentage >= 80) {
            $user->profile_completed = true;
            $user->save();
        }

        return redirect()->route('profile.index')
            ->with('success', 'Profile updated successfully!')
            ->with('phone_alert', empty($user->phone));
    }

    public function updateNotifications(Request $request)
    {
        $validated = $request->validate([
            'notifications' => ['required', 'array'],
            'notifications.email' => ['nullable', 'array'],
            'notifications.sms' => ['nullable', 'array'],
            'notifications.push' => ['nullable', 'array'],
        ]);

        $user = auth()->user();
        
        // Merge with existing preferences
        $currentPreferences = $user->notification_preferences ?? [];
        $updatedPreferences = array_merge($currentPreferences, $validated['notifications']);
        
        $user->update(['notification_preferences' => $updatedPreferences]);

        return back()->with('success', 'Notification preferences updated!');
    }
}