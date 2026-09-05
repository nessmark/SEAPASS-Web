# Modular Admin Structure

This project uses a modular file structure to separate concerns and make code reusable across multiple admin pages.

## File Structure

```
admin01/
├── public/
│   ├── css/
│   │   ├── admin.css          # Common admin styles (sidebar, header, layout)
│   │   ├── dark-mode.css      # Dark theme CSS variables
│   │   ├── light-mode.css     # Light theme CSS variables
│   │   └── dashboard.css      # Dashboard-specific styles
│   └── js/
│       ├── admin.js           # Common admin JavaScript (sidebar toggle, theme toggle)
│       └── dashboard.js       # Dashboard-specific JavaScript (charts)
├── resources/
│   └── views/
│       ├── layouts/
│       │   └── admin.blade.php    # Main admin layout template
│       ├── components/
│       │   └── sidebar.blade.php  # Reusable sidebar component
│       └── admin/
│           └── welcome_dashboard.blade.php  # Dashboard page
```

## How to Use

### Creating a New Admin Page

1. **Create your Blade view file** in `resources/views/admin/`:

```blade
@extends('layouts.admin')

@section('title', 'Your Page Title')

@push('styles')
    {{-- Add page-specific CSS if needed --}}
    <link rel="stylesheet" href="{{ asset('css/your-page.css') }}">
@endpush

@section('content')
    {{-- Your page content here --}}
    <div class="card">
        <div class="card-title">Your Content</div>
        <p>Your content goes here...</p>
    </div>
@endsection

@push('scripts')
    {{-- Add page-specific JavaScript if needed --}}
    <script src="{{ asset('js/your-page.js') }}"></script>
@endpush
```

2. **The layout automatically includes:**
   - Sidebar component
   - Header with search and theme toggle
   - Dark/Light mode CSS
   - Common admin styles
   - Footer

### Using the Sidebar Component

The sidebar is automatically included in the layout. To update navigation items, edit:
`resources/views/components/sidebar.blade.php`

To highlight the active menu item, use Laravel's route helpers:
```blade
<a href="{{ route('admin.dashboard') }}" 
   class="nav-item {{ request()->routeIs('admin.dashboard') ? 'active' : '' }}">
    <span class="nav-icon">📊</span>
    <span>Dashboard</span>
</a>
```

### Theme System

The theme system uses CSS variables defined in:
- `public/css/dark-mode.css` - Default dark theme variables
- `public/css/light-mode.css` - Light theme variables (applied when `body.light-mode` class is present)

**Available CSS Variables:**
- `--bg-primary` - Main background color
- `--bg-secondary` - Secondary background (sidebar, cards)
- `--bg-tertiary` - Tertiary background (hover states)
- `--text-primary` - Primary text color
- `--text-secondary` - Secondary text color
- `--accent-color` - Accent color (#4ecdc4)
- `--card-bg` - Card background color
- `--border-color` - Border color
- `--input-bg` - Input background color
- `--chart-grid` - Chart grid color

**Using CSS Variables in Your Styles:**
```css
.my-element {
    background-color: var(--bg-secondary);
    color: var(--text-primary);
    border: 1px solid var(--border-color);
}
```

### JavaScript Integration

**Common Admin JavaScript** (`public/js/admin.js`):
- Sidebar toggle functionality
- Theme toggle functionality
- `getCSSVariable()` helper function
- `themeChanged` custom event (dispatched when theme changes)

**Listening to Theme Changes:**
```javascript
window.addEventListener('themeChanged', function() {
    // Update your components when theme changes
    updateMyCharts();
});
```

## Benefits

1. **Reusability**: Sidebar, header, and theme system work across all admin pages
2. **Maintainability**: Update styles/functionality in one place
3. **Consistency**: All pages share the same look and feel
4. **Modularity**: Each component is separated and can be modified independently
5. **Scalability**: Easy to add new pages without duplicating code

## Example: Creating a Products Page

1. Create `resources/views/admin/products.blade.php`:
```blade
@extends('layouts.admin')

@section('title', 'Products')

@push('styles')
    <link rel="stylesheet" href="{{ asset('css/products.css') }}">
@endpush

@section('content')
    <div class="card">
        <div class="card-title">Products</div>
        {{-- Your products content --}}
    </div>
@endsection

@push('scripts')
    <script src="{{ asset('js/products.js') }}"></script>
@endpush
```

2. Add route in `routes/web.php`:
```php
Route::middleware('auth')->group(function () {
    Route::get('/admin/products', function () {
        return view('admin.products');
    })->name('admin.products');
});
```

3. Update sidebar to include Products link (if needed)

That's it! The page will automatically have:
- ✅ Sidebar navigation
- ✅ Header with search and theme toggle
- ✅ Dark/Light mode support
- ✅ Consistent styling
- ✅ Responsive design
