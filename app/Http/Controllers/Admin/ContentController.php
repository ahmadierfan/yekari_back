<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Faq;
use App\Models\Slide;
use App\Models\WorkBlock;
use App\Models\Zone;
use App\Services\Audit;
use Illuminate\Http\Request;

class ContentController extends Controller
{
    public function index()
    {
        return response()->json([
            'slides' => Slide::orderBy('sort')->get(),
            'faq' => Faq::orderBy('audience')->orderBy('sort')->get(),
            'zones' => Zone::all(),
        ]);
    }

    public function saveSlide(Request $r, ?Slide $slide = null)
    {
        $d = $r->validate(['title' => 'required|string|max:80', 'sub' => 'nullable|string|max:160', 'cta' => 'nullable|string|max:40', 'to' => 'nullable|string|max:160', 'active' => 'boolean', 'sort' => 'integer']);
        $slide = $slide?->exists ? tap($slide)->update($d) : Slide::create($d);
        Audit::log($slide->wasRecentlyCreated ? 'create' : 'update', "اسلاید «{$slide->title}» ذخیره شد");

        return response()->json(['data' => $slide]);
    }

    public function toggleSlide(Slide $slide)
    {
        $slide->update(['active' => ! $slide->active]);
        Audit::log('update', "اسلاید «{$slide->title}» ".($slide->active ? 'فعال' : 'غیرفعال').' شد');

        return response()->json(['data' => $slide]);
    }

    public function deleteSlide(Slide $slide)
    {
        $slide->delete();
        Audit::log('delete', "اسلاید «{$slide->title}» حذف شد");

        return response()->noContent();
    }

    public function saveFaq(Request $r, ?Faq $faq = null)
    {
        $d = $r->validate(['audience' => 'in:customer,courier', 'question' => 'required|string|max:255', 'answer' => 'required|string|max:3000', 'active' => 'boolean', 'sort' => 'integer']);
        $faq = $faq?->exists ? tap($faq)->update($d) : Faq::create($d);
        Audit::log($faq->wasRecentlyCreated ? 'create' : 'update', "پرسش «{$faq->question}» ذخیره شد");

        return response()->json(['data' => $faq]);
    }

    public function deleteFaq(Faq $faq)
    {
        $faq->delete();
        Audit::log('delete', "پرسش «{$faq->question}» حذف شد");

        return response()->noContent();
    }

    public function saveBlock(Request $r)
    {
        $d = $r->validate(['zone_id' => 'required|exists:zones,id', 'starts_at' => 'required|date|after:now', 'ends_at' => 'required|date|after:starts_at', 'capacity' => 'required|integer|min:1', 'bonus' => 'nullable|integer|min:0']);
        $b = WorkBlock::create($d);
        Audit::log('create', 'بلوک کاری تازه ساخته شد', null, $d);

        return response()->json(['data' => $b], 201);
    }
}
