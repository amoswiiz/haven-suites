<?php
namespace App\Support\Migration;
class Upload
{
    private bool $moved = false;
    public function __construct(private \Illuminate\Http\UploadedFile $file) {}
    public function isValid(): bool { return $this->file->isValid(); }
    public function hasMoved(): bool { return $this->moved; }
    public function getExtension(): string { return $this->file->extension(); }
    public function getClientName(): string { return $this->file->getClientOriginalName(); }
    public function getMimeType(): ?string { return $this->file->getMimeType(); }
    public function move(string $directory, string $name): void { $this->file->move($directory, $name); $this->moved = true; }
}
