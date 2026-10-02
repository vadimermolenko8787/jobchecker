{{-- One tab of the documents panel. Expects $doc ('resume' | 'cover_letter') plus the
     $vacancy, $docs, $generating and $hasResume of vacancies.show. --}}
@php
    $hash = $doc === 'resume' ? 'cv' : 'cover';
    $parts = $docs[$doc];
    $isCover = $doc === 'cover_letter';
    $busy = in_array($generating, [$doc, 'both'], true);
    $formId = "generate-{$hash}";
    // The fragment survives the redirect back, so the page reopens on this tab.
    $generateUrl = route('vacancies.generate', [$vacancy, $doc]) . "#{$hash}";
    $languages = \App\Services\DocumentGenerator::languageLabels();
    $defaultLanguage = \App\Models\Setting::get('cover_letter_language');
@endphp
<div class="doc-pane" data-pane="{{ $hash }}" @if ($hash !== 'cv') hidden @endif>
    <div class="doc-head">
        <div class="tabs" role="tablist">
            <button type="button" role="tab" @class(['tab', 'is-active' => $hash === 'cv']) data-tab="cv" aria-selected="{{ $hash === 'cv' ? 'true' : 'false' }}"><span @class(['tdot', '-ok' => $docs['resume']])></span>CV</button>
            <button type="button" role="tab" @class(['tab', 'is-active' => $hash === 'cover']) data-tab="cover" aria-selected="{{ $hash === 'cover' ? 'true' : 'false' }}"><span @class(['tdot', '-ok' => $docs['cover_letter']])></span>Cover letter</button>
        </div>
        @if ($parts)
            <form id="{{ $formId }}" method="post" action="{{ $generateUrl }}" class="stack" style="gap:8px"
                  data-confirm="{{ __('Regenerating will overwrite your manual edits. Continue?') }}">
                @csrf
                @if ($isCover)
                    <select name="lang" aria-label="{{ __('Cover letter language') }}" style="width:auto;padding:6px 10px;font-size:13px">
                        @foreach ($languages as $code => $label)
                            <option value="{{ $code }}" @selected($defaultLanguage === $code)>{{ $label }}</option>
                        @endforeach
                    </select>
                    <button type="button" class="btn btn-sm btn-info" data-toggle-instructions>{{ __('Instructions') }}</button>
                @endif
                <button type="submit" class="btn btn-sm btn-info" @disabled(! $hasResume || $generating)
                        @unless ($hasResume) title="{{ __('Upload a base resume on the dashboard to generate documents.') }}" @endunless>
                    {{ $isCover ? __('Regenerate') : __('Regenerate CV') }}
                </button>
            </form>
        @endif
    </div>

    @if ($parts && $isCover)
        <div class="doc-instructions" data-instructions hidden>
            <textarea name="extra_instructions" form="{{ $formId }}" rows="2" maxlength="2000" aria-label="{{ __('Extra instructions') }}" style="font-size:13px"
                      placeholder="{{ __('Extra instructions (optional): what to emphasize, what to leave out…') }}">{{ old('extra_instructions') }}</textarea>
        </div>
    @endif

    @if ($busy)
        <div class="doc-empty">
            <div class="stack scanning" style="padding:16px;border-radius:8px;background:var(--raised)">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="var(--signal)" stroke-width="2" style="animation:pulse 1.1s infinite"><circle cx="12" cy="12" r="9"/></svg>
                <span>{{ __('Generating :what… the page will refresh by itself.', ['what' => $isCover ? 'cover letter' : __('resume')]) }}</span>
            </div>
        </div>
    @elseif ($parts)
        <div class="doc-bar">
            <div class="stack" style="gap:8px">
                <div class="vswitch">
                    <button type="button" class="vbtn is-active" data-mode="pdf">{{ __('PDF preview') }}</button>
                    <button type="button" class="vbtn" data-mode="edit">{{ __('Editor') }}</button>
                </div>
                <button type="button" class="btn btn-sm btn-ghost" data-view="edit" data-wide-toggle hidden aria-pressed="false"
                        data-label-on="{{ __('Exit full screen') }}" data-label-off="{{ __('Full screen') }}">{{ __('Full screen') }}</button>
            </div>
            <div class="stack" style="gap:8px">
                <a href="{{ route('vacancies.download', [$vacancy, $doc]) }}" class="btn btn-sm btn-ghost">{{ __('Source (:ext)', ['ext' => '.' . $parts['ext']]) }}</a>
                <a href="{{ route('vacancies.download', [$vacancy, $doc, 'pdf']) }}" class="btn btn-sm btn-primary">{{ __('Download PDF') }}</a>
            </div>
        </div>
        <iframe class="doc-frame" data-view="pdf" src="{{ route('vacancies.pdf', [$vacancy, $doc]) }}" title="{{ $isCover ? 'Cover letter' : 'CV' }} PDF" loading="lazy"></iframe>
        <form method="post" action="{{ route('vacancies.documents.update', [$vacancy, $doc]) }}" class="doc-editor" data-view="edit" data-editor hidden>
            @csrf
            @method('PUT')
            <textarea name="html" hidden data-editor-source>{{ $parts['body'] }}</textarea>
            <textarea hidden data-editor-style>{{ $parts['style'] }}</textarea>
            <div class="doc-editor-area"><textarea data-editor-target></textarea></div>
            <div class="doc-foot">
                <button type="submit" class="btn btn-sm btn-primary">{{ __('Save') }}</button>
                <button type="button" class="btn btn-sm btn-ghost" data-mode="pdf">{{ __('Cancel') }}</button>
                <span class="faint" style="margin-left:auto;font-size:12px">{{ __('The PDF is rebuilt after saving') }}</span>
            </div>
        </form>
    @else
        <div class="doc-empty">
            <span style="width:52px;height:52px;border-radius:14px;background:var(--info-dim);color:var(--info);display:grid;place-items:center">
                <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M14 3v5h5M8 2h7l5 5v13a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2Z"/><path d="M9 13h6M9 17h4"/></svg>
            </span>
            <div>
                <div style="font-weight:600;font-size:16px">{{ $isCover ? __('Cover letter not created yet') : __('CV not created yet') }}</div>
                <div class="help" style="margin-top:4px;font-size:13px">
                    {{ $isCover
                        ? __('Claude will write a letter for this vacancy from your resume text, it takes a minute or two.')
                        : __('Claude will tailor your resume to this vacancy in the design of your PDF, it takes a minute or two.') }}
                </div>
            </div>
            <form method="post" action="{{ $generateUrl }}" style="display:flex;flex-direction:column;gap:10px;width:100%;max-width:420px;text-align:left">
                @csrf
                @if ($isCover)
                    <label class="lab" for="cover-lang">{{ __('Cover letter language') }}</label>
                    <select id="cover-lang" name="lang">
                        @foreach ($languages as $code => $label)
                            <option value="{{ $code }}" @selected($defaultLanguage === $code)>{{ $label }}</option>
                        @endforeach
                    </select>
                    <label class="lab" for="cover-instructions">{{ __('Extra instructions') }}</label>
                    <textarea id="cover-instructions" name="extra_instructions" rows="3" maxlength="2000" style="font-size:13px"
                              placeholder="{{ __('Extra instructions (optional): what to emphasize, what to leave out…') }}">{{ old('extra_instructions') }}</textarea>
                @endif
                <button type="submit" class="btn btn-info" @disabled(! $hasResume || $generating)>
                    {{ $isCover ? __('Generate cover letter') : __('Generate CV') }}
                </button>
            </form>
            @unless ($hasResume)
                <div class="faint" style="font-size:12.5px">{{ __('Upload a base resume on the dashboard to generate documents.') }}</div>
            @endunless
        </div>
    @endif
</div>
