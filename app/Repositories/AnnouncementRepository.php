<?php

namespace App\Repositories;

use App\Models\Announcement;

class AnnouncementRepository
{

    public function AnnouncementActive()
    {
        return Announcement::active()
            ->orderByDisplayOrder()
            ->get();
    }


    public function all()
    {
        return Announcement::orderByDisplayOrder()->get();
    }

    public function find($id)
    {
        return Announcement::findOrFail($id);
    }

    public function create(array $data)
    {
        return Announcement::create($data);
    }

    public function update(Announcement $announcement, array $data)
    {
        $announcement->update($data);
        return $announcement;
    }

    public function delete(Announcement $announcement)
    {
        return $announcement->delete();
    }
}
