<?php

namespace App\Http\Controllers;

use App\Models\Boat;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\File;

class BoatController extends Controller
{
    /**
     * Get the path for boat images directory
     */
    private function getBoatsImagesPath()
    {
        return public_path('Boats_images');
    }

    /**
     * Store uploaded image and return the filename
     */
    private function storeImage($file)
    {
        // Ensure directory exists
        $imagesPath = $this->getBoatsImagesPath();
        if (!File::exists($imagesPath)) {
            File::makeDirectory($imagesPath, 0755, true);
        }

        // Generate unique filename
        $filename = time() . '_' . uniqid() . '.' . $file->getClientOriginalExtension();
        
        // Move file to public/Boats_images directory
        $file->move($imagesPath, $filename);
        
        return $filename;
    }

    /**
     * Delete image file
     */
    private function deleteImage($filename)
    {
        $imagePath = $this->getBoatsImagesPath() . '/' . $filename;
        if (File::exists($imagePath)) {
            File::delete($imagePath);
        }
    }

    public function index()
    {
        $boats = Boat::query()->orderBy('name')->get();

        return view('admin.boats_dashboard', [
            'boats' => $boats,
        ]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:100', 'unique:boats,name'],
            'owner' => ['nullable', 'string', 'max:100'],
            'operator' => ['nullable', 'string', 'max:100'],
            'boat_number' => ['nullable', 'string', 'max:50'],
            'license_number' => ['nullable', 'string', 'max:100'],
            'passenger_capacity' => ['nullable', 'integer', 'min:0'],
            'image' => ['nullable', 'image', 'mimes:jpeg,png,jpg,gif', 'max:2048'],
            'status' => ['required', 'in:Active,Under Maintenance,Out of Service'],
        ]);

        // Handle image upload
        if ($request->hasFile('image')) {
            $validated['image'] = $this->storeImage($request->file('image'));
        }

        Boat::create($validated);

        return redirect()->route('admin.boats')->with('success', 'Boat added successfully.');
    }

    public function update(Request $request, Boat $boat)
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:100', 'unique:boats,name,' . $boat->id],
            'owner' => ['nullable', 'string', 'max:100'],
            'operator' => ['nullable', 'string', 'max:100'],
            'boat_number' => ['nullable', 'string', 'max:50'],
            'license_number' => ['nullable', 'string', 'max:100'],
            'passenger_capacity' => ['nullable', 'integer', 'min:0'],
            'image' => ['nullable', 'image', 'mimes:jpeg,png,jpg,gif', 'max:2048'],
            'status' => ['required', 'in:Active,Under Maintenance,Out of Service'],
        ]);

        // Handle image upload
        if ($request->hasFile('image')) {
            // Delete old image if exists
            if ($boat->image) {
                $this->deleteImage($boat->image);
            }
            $validated['image'] = $this->storeImage($request->file('image'));
        }

        $boat->update($validated);

        return redirect()->route('admin.boats')->with('success', 'Boat updated successfully.');
    }

    public function updateStatus(Request $request, Boat $boat)
    {
        $validated = $request->validate([
            'status' => ['required', 'in:Active,Under Maintenance,Out of Service'],
        ]);

        $boat->update([
            'status' => $validated['status'],
        ]);

        return redirect()->route('admin.boats')->with('success', 'Boat status updated successfully.');
    }

    public function destroy(Boat $boat)
    {
        // Delete image if exists
        if ($boat->image) {
            $this->deleteImage($boat->image);
        }

        $boat->delete();

        return redirect()->route('admin.boats')->with('success', 'Boat deleted successfully.');
    }
}

