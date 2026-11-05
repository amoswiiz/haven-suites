<?php

namespace App\Http\Controllers;

use Illuminate\Database\Query\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;

class StaffProfileController extends Controller
{
    private const DOCUMENT_TYPES = [
        'national_id' => 'National ID', 'passport' => 'Passport',
        'alien_id' => 'Alien ID', 'driving_licence' => 'Driving licence',
    ];

    private function authorizeSelf(): void
    {
        abort_unless(auth()->user()?->active && auth()->user()->can('staff-profile.self'), 403);
    }

    private function authorizeReview(): void
    {
        abort_unless(auth()->user()?->active && auth()->user()->inGroup('admin', 'superadmin')
            && auth()->user()->can('staff-profile.review'), 403);
    }

    private function profiles(): Builder
    {
        return DB::table('staff_profiles')->where('tenant_id', app('currentTenant')->id);
    }

    private function phoneNumberRule(): \Closure
    {
        return static function (string $attribute, mixed $value, \Closure $fail): void {
            $digits = is_string($value) ? preg_replace('/[^0-9]/', '', $value) : '';
            if (! is_string($value) || ! preg_match('/^\+?[0-9 ()-]+$/D', $value)
                || strlen($digits) < 7 || strlen($digits) > 15) {
                $fail('Enter a phone number containing 7 to 15 digits. Spaces, parentheses and hyphens are allowed.');
            }
        };
    }

    public function edit(): Response
    {
        $this->authorizeSelf();

        return response()->view('staff_profiles.edit', [
            'profile' => $this->profiles()->where('user_id', auth()->id())->first(),
            'documentTypes' => self::DOCUMENT_TYPES,
        ])->header('Cache-Control', 'private, no-store');
    }

    public function update(Request $request): RedirectResponse
    {
        $this->authorizeSelf();
        $existing = $this->profiles()->where('user_id', auth()->id())->first();
        $data = $request->validate([
            'version' => 'required|integer|min:0',
            'legal_name' => 'required|string|max:180',
            'phone' => ['required', 'string', 'max:40', $this->phoneNumberRule()],
            'email' => 'nullable|email|max:180',
            'date_of_birth' => 'nullable|date_format:Y-m-d|before:today|after:1900-01-01',
            'address' => 'nullable|string|max:1000',
            'emergency_contact_name' => 'nullable|required_with:emergency_contact_phone|string|max:180',
            'emergency_contact_phone' => ['nullable', 'required_with:emergency_contact_name', 'string', 'max:40', $this->phoneNumberRule()],
            'document_type' => ['required', 'string', Rule::in(array_keys(self::DOCUMENT_TYPES))],
            'identity_document' => [
                $existing ? 'nullable' : 'required', 'file', 'mimes:jpg,jpeg,png,pdf',
                'mimetypes:image/jpeg,image/png,application/pdf', 'extensions:jpg,jpeg,png,pdf', 'max:5120',
            ],
        ]);

        $newPath = null;
        $oldPath = null;
        try {
            $file = $request->file('identity_document');
            if ($file) {
                $newPath = $file->store('staff-profiles/'.app('currentTenant')->id.'/'.auth()->id(), 'local');
                if (! $newPath) {
                    throw new \RuntimeException('Identification document could not be stored.');
                }
            }
            DB::transaction(function () use ($data, $file, $newPath, &$oldPath): void {
                DB::table('tenant_user')->where('tenant_id', app('currentTenant')->id)
                    ->where('user_id', auth()->id())->lockForUpdate()->firstOrFail();
                $profile = $this->profiles()->where('user_id', auth()->id())->lockForUpdate()->first();
                if ((int) ($profile->version ?? 0) !== (int) $data['version']) {
                    throw ValidationException::withMessages(['profile' => 'Your profile changed in another window. Reload before saving.']);
                }
                if (! $file && $profile && $data['document_type'] !== $profile->document_type) {
                    throw ValidationException::withMessages(['identity_document' => 'Upload the matching document when changing its type.']);
                }
                $values = $data;
                unset($values['version'], $values['identity_document']);
                $values += ['version' => ($profile->version ?? 0) + 1, 'updated_at' => now()];
                if ($newPath) {
                    $oldPath = $profile->document_path ?? null;
                    $values += [
                        'document_path' => $newPath,
                        'document_mime_type' => $file->getMimeType(),
                        'document_original_name' => mb_substr(basename($file->getClientOriginalName()), 0, 255),
                    ];
                }
                if ($profile) {
                    $this->profiles()->where('id', $profile->id)->update($values);
                } else {
                    DB::table('staff_profiles')->insert($values + [
                        'tenant_id' => app('currentTenant')->id, 'user_id' => auth()->id(), 'created_at' => now(),
                    ]);
                }
            });
        } catch (\Throwable $exception) {
            if ($newPath) {
                Storage::disk('local')->delete($newPath);
            }
            if ($exception instanceof ValidationException) {
                throw $exception;
            }
            report($exception);

            return back()->withInput()->withErrors(['profile' => 'Your profile could not be saved. Please try again.']);
        }

        if ($oldPath && $oldPath !== $newPath && $this->validPath($oldPath, (int) auth()->id())) {
            try {
                if (! Storage::disk('local')->delete($oldPath)) {
                    logger()->warning('Staff profile old-document cleanup was not completed.', [
                        'tenant_id' => app('currentTenant')->id, 'user_id' => auth()->id(),
                    ]);
                }
            } catch (\Throwable $exception) {
                report($exception);
            }
        }

        return redirect()->route('staff-profile.edit', app('currentTenant')->slug)->with('success', 'Your staff profile has been saved.');
    }

    private function validPath(string $path, int $userId): bool
    {
        return (bool) preg_match('#^staff-profiles/'.(int) app('currentTenant')->id.'/'.$userId.'/[a-zA-Z0-9]{40}\.(?:jpg|jpeg|png|pdf)$#D', $path);
    }

    private function download(object $profile): StreamedResponse
    {
        abort_unless($this->validPath($profile->document_path, (int) $profile->user_id)
            && in_array($profile->document_mime_type, ['image/jpeg', 'image/png', 'application/pdf'], true)
            && Storage::disk('local')->exists($profile->document_path), 404);

        return Storage::disk('local')->download($profile->document_path,
            'staff-identification-'.(int) $profile->id.'.'.pathinfo($profile->document_path, PATHINFO_EXTENSION), [
                'Content-Type' => $profile->document_mime_type,
                'Cache-Control' => 'private, no-store, max-age=0', 'Pragma' => 'no-cache', 'X-Content-Type-Options' => 'nosniff',
            ]);
    }

    public function document(): StreamedResponse
    {
        $this->authorizeSelf();

        return $this->download($this->profiles()->where('user_id', auth()->id())->firstOrFail());
    }

    public function index(): Response
    {
        $this->authorizeReview();
        $profiles = $this->profiles()->select('id', 'user_id', 'legal_name', 'phone', 'updated_at')->orderBy('legal_name')->paginate(25);

        return response()->view('staff_profiles.index', ['profiles' => $profiles])->header('Cache-Control', 'private, no-store');
    }

    public function show(string $tenant, int $id): Response
    {
        $this->authorizeReview();

        return response()->view('staff_profiles.show', [
            'profile' => $this->profiles()->where('id', $id)->firstOrFail(), 'documentTypes' => self::DOCUMENT_TYPES,
        ])->header('Cache-Control', 'private, no-store');
    }

    public function reviewDocument(string $tenant, int $id): StreamedResponse
    {
        $this->authorizeReview();

        return $this->download($this->profiles()->where('id', $id)->firstOrFail());
    }
}
