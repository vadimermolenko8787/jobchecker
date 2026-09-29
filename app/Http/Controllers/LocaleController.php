<?php

namespace App\Http\Controllers;

use App\Models\Setting;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class LocaleController extends Controller
{
    /**
     * The app has a single user, so the language is a global setting rather than a
     * per-session one: background runs and Telegram messages follow it too.
     */
    public function update(Request $request)
    {
        $data = $request->validate(['locale' => ['required', Rule::in(Setting::LOCALES)]]);
        Setting::set('locale', $data['locale']);

        return back();
    }
}
