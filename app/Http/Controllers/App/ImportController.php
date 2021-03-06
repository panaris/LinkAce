<?php

namespace App\Http\Controllers\App;

use App\Helper\HtmlMeta;
use App\Helper\LinkIconMapper;
use App\Http\Controllers\Controller;
use App\Http\Requests\DoImportRequest;
use App\Models\Link;
use App\Models\Tag;
use Carbon\Carbon;
use Exception;
use Illuminate\Contracts\Filesystem\FileNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;
use Illuminate\View\View;
use Shaarli\NetscapeBookmarkParser\NetscapeBookmarkParser;

/**
 * Class ImportController
 *
 * @package App\Http\Controllers\App
 */
class ImportController extends Controller
{
    public function getImport(): View
    {
        return view('actions.import.import');
    }

    /**
     * Permanently delete entries for a model from the trash
     *
     * @param DoImportRequest $request
     * @return JsonResponse
     * @throws FileNotFoundException
     */
    public function doImport(DoImportRequest $request)
    {
        $data = $request->file('import-file')->get();

        $parser = new NetscapeBookmarkParser();

        try {
            $links = $parser->parseString($data);
        } catch (Exception $e) {
            Log::error($e->getMessage());

            return response()->json([
                'success' => false,
                'message' => trans('import.import_error'),
            ]);
        }

        if (empty($links)) {
            // This will never be reached at the moment because the bookmark parser is not capable of handling
            // empty bookmarks exports. See https://github.com/shaarli/netscape-bookmark-parser/issues/50
            return response()->json([
                'success' => false,
                'message' => trans('import.import_empty'),
            ]);
        }

        $userId = auth()->id();
        $imported = 0;
        $skipped = 0;

        foreach ($links as $link) {
            if (Link::whereUrl($link['uri'])->first()) {
                $skipped++;
                continue;
            }

            $linkMeta = HtmlMeta::getFromUrl($link['uri']);

            $title = $link['title'] ?: $linkMeta['title'];

            $newLink = Link::create([
                'user_id' => $userId,
                'url' => $link['uri'],
                'title' => $title,
                'description' => $link['note'] ?: $linkMeta['description'],
                'icon' => LinkIconMapper::mapLink($link['uri']),
                'is_private' => $link['pub'],
                'created_at' => Carbon::createFromTimestamp($link['time']),
                'updated_at' => Carbon::now(),
            ]);

            // Get all tags
            if (!empty($link['tags'])) {
                $tags = explode(' ', $link['tags']);

                foreach ($tags as $tag) {
                    $newTag = Tag::firstOrCreate([
                        'user_id' => $userId,
                        'name' => $tag,
                    ]);

                    $newLink->tags()->attach($newTag->id);
                }
            }

            $imported++;
        }

        return response()->json([
            'success' => true,
            'message' => trans('import.import_successfully', [
                'imported' => $imported,
                'skipped' => $skipped,
            ]),
        ]);
    }
}
