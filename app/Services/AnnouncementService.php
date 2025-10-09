<?php

namespace App\Services;

use App\Models\Announcement;
use App\Repositories\AnnouncementRepository;
use Illuminate\Support\Facades\Storage;


class AnnouncementService
{
    protected $repo;

    public function __construct(AnnouncementRepository $repo)
    {
        $this->repo = $repo;
    }

    public function allActive()
    {
        return $this->repo->AnnouncementActive();
    }


    public function list()
    {
        return $this->repo->all();
    }

    public function create(array $data)
    {
        if (request()->hasFile('icon')) {
            $data['icon'] = request()->file('icon')->store('announcements', 'spaces');
        }
        return $this->repo->create($data);
    }

    public function update(Announcement $announcement, $request)
    {
        $data = $request->validated();
        if (request()->hasFile('icon')) {
            if ($announcement->icon) {
                $originalPath = ltrim($announcement->getRawOriginal('icon'), '/');
                Storage::disk('spaces')->delete($originalPath);
            }
            $data['icon'] = request()->file('icon')->store('announcements', 'spaces');
        }




        return $this->repo->update($announcement, $data);
    }

    public function delete(Announcement $announcement)
    {
        if ($announcement->icon) {
            $originalPath = ltrim($announcement->getRawOriginal('icon'), '/');
            Storage::disk('spaces')->delete($originalPath);
        }
        return $this->repo->delete($announcement);
    }

    public function show($id)
    {
        return $this->repo->find($id);
    }
}
