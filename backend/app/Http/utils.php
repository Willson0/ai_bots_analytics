<?php

namespace app\Http;


use App\Models\Admin;
use App\Models\AdminCookie;
use App\Models\Course;
use App\Models\Event;
use App\Models\Lesson;
use App\Models\Notification;
use App\Models\Picture;
use App\Models\Post;
use App\Models\Proxy;
use App\Models\Service;
use App\Models\User;
use Carbon\Carbon;
use Exception;
use Google\Cloud\Translate\V3\Client\TranslationServiceClient;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use OpenAI\Client;
use Telegram\Bot\Laravel\Facades\Telegram;

class utils
{
    static public function sendMessage ($chat_id, $text) {
        $token = env("TELEGRAM_BOT_TOKEN"); // Токен бота
        $url = "https://api.telegram.org/bot$token/sendMessage";

        $response = Http::post($url, [
            'chat_id' => $chat_id,
            'text' => $text,
            "reply_markup" => [
                "remove_keyboard" => true
            ]
        ]);

        if ($response->ok()) return 1;
        else return 0;
    }

    static public function requestFullname ($chat_id) {
        $token = env("TELEGRAM_BOT_TOKEN");
        $url = "https://api.telegram.org/bot$token/sendMessage";

        Http::post($url, [
            'chat_id' => $chat_id,
            'text' => "Телефон успешно обновлен.",
            "reply_markup" => [
                "remove_keyboard" => true,
            ]
        ]);

        $response = Http::post($url, [
            'chat_id' => $chat_id,
            'text' => "Отправьте своё ФИО в формате 'Фамилия Имя Отчество'",
            "reply_markup" => [
                "inline_keyboard" => [
                    [
                        [
                            "text" => "Пропустить",
                            "callback_data" => "refuse_fullname"
                        ]
                    ]
                ],
            ]
        ]);

        if ($response->ok()) return 1;
        else return 0;
    }


    public static function isSafe(string $botToken, string $initData): bool
    {
        [$checksum, $sortedInitData] = self::convertInitData($initData);
        $secretKey                   = hash_hmac('sha256', $botToken, 'WebAppData', true);
        $hash                        = bin2hex(hash_hmac('sha256', $sortedInitData, $secretKey, true));

        return 0 === strcmp($hash, $checksum);
    }

    private static function convertInitData(string $initData): array
    {
        $initDataArray = explode('&', rawurldecode($initData));
        $needle        = 'hash=';
        $hash          = '';

        foreach ($initDataArray as &$data) {
            if (substr($data, 0, \strlen($needle)) === $needle) {
                $hash = substr_replace($data, '', 0, \strlen($needle));
                $data = null;
            }
        }
        $initDataArray = array_filter($initDataArray);
        sort($initDataArray);

        return [$hash, implode("\n", $initDataArray)];
    }

    public static function getSettings()
    {
        return json_decode(file_get_contents(storage_path('app/settings.json')), true);
    }

    public static function returnToAdmin ($menu, $user, $text) {
        $user->step = "admin_menu";
        $user->save();

        $keyboard = [];
        foreach ($menu["menu"] as $button) $keyboard[] = ["text" => $button["name"]];
        $keyboard = array_chunk($keyboard, 2);

        $token = env("TELEGRAM_BOT_TOKEN");

        $url = "https://api.telegram.org/bot$token/sendMessage";
        Http::post($url, [
            'chat_id' => $user->telegram_id,
            'text' => $text,
            "reply_markup" => [
                "keyboard" => $keyboard,
            ]
        ]);
    }

    public static function answerData ($text, $request, $user, $deleteMarkup = true) {
        $token = env("TELEGRAM_BOT_TOKEN"); // Токен бота
        $editurl = "https://api.telegram.org/bot$token/editMessageReplyMarkup";
        $url = "https://api.telegram.org/bot$token/answerCallbackQuery";

        Http::post($url, [
            "callback_query_id" => $request["callback_query"]["id"],
            'text' => $text,
        ]);

        if ($deleteMarkup)
            Http::post($editurl, [
                "chat_id" => $user,
                "message_id" => $request["callback_query"]["message"]["message_id"],
                "reply_markup" => [
                    "inline_keyboard" => [[]],
                ],
            ]);
    }
    static function gen_cookie ($user, $isadmin = false) {
        if ($isadmin) $cookieclass = AdminCookie::class;
        else $cookieclass = Cookie::class;

        do $cookie = self::gen_str(32);
        while ($cookieclass::where("cookie", $cookie)->exists());

        $cookieclass::create([
            "user_id" => $user->id,
            "cookie" => $cookie
        ]);
        return $cookie;
    }

    static public function gen_str ($length) {
        $permitted_chars = '0123456789abcdefghijklmnopqrstuvwxyz';
        $random_string = '';
        for($i = 0; $i < $length; $i++) {
            $random_character = $permitted_chars[mt_rand(0, strlen($permitted_chars) - 1)];
            $random_string .= $random_character;
        }
        return $random_string;
    }

    static function index ($class, $request, $forAdmin = false) {
        $limit = 10;
        if ($request->has("limit")) $limit = $request->limit;

        $query = $class::take($limit);

        if ($request->has("sort")) $query->orderby("id", $request->sort);
        if ($request->has('datesort')) $query->orderby('id', $request->datesort);
        if ($request->has('offset')) $query->offset($request->offset);
        if ($request->has('namesort')) $query->orderby('title', $request->namesort);
        if ($request->has("s")) {
            if ($class === User::class) $query->where("fullname", "like", "%$request->s%")->orWhere("telegram_id", "like", "%$request->s%");
            else $query->where("title", "like", "%$request->s%");
        }

        if ($forAdmin) {
            $countpage = ceil($query->count()/$limit);
            if ($request->has('page') and $limit) $query->skip(($request->page - 1) * $limit);

            $response["data"] = $query->get();
            $response["count"] = $countpage;

            return response()->json($response);
        }

        return $query->get();
    }

    static function sendAdmin ($message) {
        $admins = Admin::all();
        foreach ($admins as $admin) {
            $token = env("TELEGRAM_BOT_TOKEN"); // Токен бота
            $url = "https://api.telegram.org/bot$token/sendMessage";

            Http::post($url, [
                'chat_id' => $admin->telegram_id,
                'text' => $message,
                'parse_mode' => 'Markdown'
            ]);
        }
    }

    static function transliterate ($text) {
        $rus = [
            'А','Б','В','Г','Д','Е','Ё','Ж','З','И','Й',
            'К','Л','М','Н','О','П','Р','С','Т','У','Ф',
            'Х','Ц','Ч','Ш','Щ','Ъ','Ы','Ь','Э','Ю','Я',
            'а','б','в','г','д','е','ё','ж','з','и','й',
            'к','л','м','н','о','п','р','с','т','у','ф',
            'х','ц','ч','ш','щ','ъ','ы','ь','э','ю','я'
        ];

        $lat = [
            'A','B','V','G','D','E','E','Zh','Z','I','Y',
            'K','L','M','N','O','P','R','S','T','U','F',
            'H','Ts','Ch','Sh','Sch','','Y','','E','Yu','Ya',
            'a','b','v','g','d','e','e','zh','z','i','y',
            'k','l','m','n','o','p','r','s','t','u','f',
            'h','ts','ch','sh','sch','','y','','e','yu','ya'
        ];

        $text = str_replace($rus, $lat, $text);
        $text = preg_replace('/\s+/', '_', $text);
        $text = preg_replace('/[^A-Za-z0-9_]/', '', $text);
        $text = preg_replace('/_+/', '_', $text);
        $text = trim($text, '_');
        return $text;
    }

    static function addNotification ($user, $action, $type, $object) {
        $tables = [
            "post" => Post::class,
            "service" => Service::class,
            "event" => Event::class,
            "user" => User::class,
        ];
        $object = $tables[$type]::find($object);

        $title = "";
        if ($action == "favourite") {
            $object->get();

            $title = "Вашу услугу добавили в избранное";
            $description = "Пользователь " . ($user->fullname) . " добавил услугу {$object->title} вашей собаки в избранное";
        } else if ($action == "subscribe") {
            $title = "На вас подписались";
            $description = "Пользователь {$object->fullname} подписался на вас";
        } else if ($action == "accept") {
            $title = "Ваше мероприятие одобрили";
            $description = "Администратор одобрил ваше мероприятие";
        }

        Notification::create([
            "user_id" => $object->user->id ?? $object->id,
            "title" => $title,
            "description" => $description,
            "type" => $type,
            "object_id" => $object->id,
            "readed" => false,
        ]);


        $owner = User::find($object->user->id ?? $object->id);
        if ($owner->notification) {
            $des = $description;
            $escape = ['_', '*', '[', ']', '(', ')', '~', '`', '>', '#', '+', '-', '=', '|', '{', '}', '.', '!'];
            foreach ($escape as $char) {
                $des = str_replace($char, '\\' . $char, $des);
            }

            Telegram::sendMessage([
                "chat_id" => $owner->telegram_id,
                "text" => "*🔔 $title*
>$des",
                "parse_mode" => "MarkdownV2"
            ]);
        }

        return true;
    }

    static function update ($post, $user, $request, $type = "post") {
        if ($post->user_id !== $user->id) abort (409);

        $data = $request->validated();
        $post->fill($data)->save();

        if ($request->has("delete_pictures"))
            foreach ($request->delete_pictures as $picture) Picture::destroy($picture);

        $time = time();
        $index = 0;
        $pictures = $request->file('pictures', []);

        foreach ($pictures as &$picture) {
            Storage::disk("public")->putFileAs($type, $picture, "image_$time" . $index . "." . $picture->extension());
            $picture = "$type/image_$time" . $index . "." . $picture->extension();
            $index++;
        }

        if ($request->has("number_main_picture") AND $request->number_main_picture < sizeof($pictures)) {
            $oldPictures = Picture::where("type", $type)->where("object_id", $post->id)->get();
            Picture::where("type", $type)->where("object_id", $post->id)->delete();

            Picture::create([
                "type" => $type,
                "object_id" => $post->id,
                "url" => $pictures[$request->number_main_picture],
            ]);
            unset($pictures[$request->number_main_picture]);

            foreach ($oldPictures as $oldPicture) {
                Picture::create([
                    "type" => $type,
                    "object_id" => $post->id,
                    "url" => $oldPicture->url,
                ]);
            }
        }

        foreach ($pictures as $picture) {
            Picture::create([
                "type" => $type,
                "object_id" => $post->id,
                "url" => $picture,
            ]);
        }

        return response()->json($post);
    }

    static function checkAccessToLesson ($user, $lesson) {
        $course = $lesson->course;

        if ($lesson->number === 1) {
            $required_course = Course::where("id", $course->required_course)->first();
            if ($required_course) {
                $hasNotPassed = Lesson::where('course_id', $required_course->id)
                    ->whereDoesntHave('userLessons', function($q) use ($user) {
                        $q->where('user_id', $user->id)
                            ->where(function($query) {
                                $query->whereRaw('lessons.count_tries > 0 and user_lessons.points >= 50')
                                    ->orWhereRaw('lessons.count_tries <= 0 and user_lessons.points > -1');
                            });
                    })
                    ->exists();
                if ($hasNotPassed) abort (403, "Предыдущий курс не пройден!");
            }
        } else {
            $hasNotPassed = Lesson::where('course_id', $course->id)->where("number", "<", $lesson->number)
                ->whereDoesntHave('userLessons', function($q) use ($user) {
                    $q->where('user_id', $user->id)
                        ->where(function($query) {
                            $query->whereRaw('lessons.count_tries > 0 and user_lessons.points >= 50')
                                ->orWhereRaw('lessons.count_tries <= 0 and user_lessons.points > -1');
                        });
                })
                ->exists();
            if ($hasNotPassed) abort (403, "Предыдущие уроки в этом курсе не пройдены!");
        }
    }

    static function addData ($user, $field, $count) {
        $data = json_decode($user->data, true);
        if (!is_array($data)) $data = [];

        if (!isset($data[$field])) $data[$field] = 0;
        $data[$field] += $count;

        $user->update([
            "data" => json_encode($data)
        ]);
    }

    static function getExtension ($audio) {
        $mime = $audio->getMimeType();
        $extension = match ($mime) {
            'audio/webm', "video/webm" => 'webm',
            'audio/wav' => 'wav',
            'audio/ogg' => 'ogg',
            'audio/mp3', 'audio/mpeg' => 'mp3',
            'audio/mp4', 'video/mp4' => 'm4a',
            default => 'dat',
        };
        Log::critical("ORIGINAL MIME $mime");

        return $audio->getClientOriginalExtension() ?: $extension;
    }

    static function logging ($user, $message, $data) {
        try {
            return \App\Models\Log::create([
                "user_id" => $user,
                "text" => $message,
                "data" => json_encode($data),
            ]);
        } catch (\Throwable $th) {
            Log::critical($th);
            return "";
        }
    }

    const CACHE_KEY = 'fake_online_count';
    const CACHE_LAST_UPDATED = 'fake_online_last_updated';

    const ranges = [
        // hour => [min, max]
        [2, 10],   // 0
        [2, 10],   // 1
        [2, 10],   // 2
        [2, 10],   // 3
        [2, 10],   // 4
        [2, 10],   // 5
        [13, 27],  // 6
        [13, 27],  // 7
        [13, 27],  // 8
        [13, 27],  // 9
        [35, 102], // 10
        [35, 102], // 11
        [35, 102], // 12
        [35, 102], // 13
        [35, 102], // 14
        [35, 102], // 15
        [35, 102], // 16
        [35, 102], // 17
        [35, 102], // 18
        [35, 102], // 19
        [13, 27],  // 20
        [13, 27],  // 21
        [13, 27],  // 22
        [2, 10],   // 23
    ];

    static public function getOnline() // OPTIMIZATION
    {
        $now = Carbon::now('Europe/Moscow');
        $hour = $now->hour;

        [$min, $max] = self::ranges[$hour];

        $count = Cache::get(self::CACHE_KEY);
        $lastUpdated = Cache::get(self::CACHE_LAST_UPDATED);

        if ($count === null) {
            $count = rand($min, $max);
            Cache::put(self::CACHE_KEY, $count, 600);
            Cache::put(self::CACHE_LAST_UPDATED, $now->timestamp, 600);
            return $count;
        }

        $randPeriod = rand(10, 23);
        $lastUpdated = $lastUpdated ? Carbon::createFromTimestamp($lastUpdated) : Carbon::now()->subMinutes(99);

        if (abs($now->diffInSeconds($lastUpdated)) > $randPeriod) {
            $deltaOptions = [-2, -1, 0, 1, 2, -1, 1, 0, 0];
            $change = $deltaOptions[array_rand($deltaOptions)];
            $newCount = $count + $change;

            if ($newCount < $min) $newCount = $min;
            if ($newCount > $max) $newCount = $max;

            Cache::put(self::CACHE_KEY, $newCount, 600);
            Cache::put(self::CACHE_LAST_UPDATED, $now->timestamp, 600);
            return $newCount;
        }
        return $count;
    }

    static public function setTrial ($days = 7): int {
        Cache::forever('trial', $days);
        return $days;
    }

    static public function getTrial () {
        $cache = Cache::get('trial');
        if ($cache === null) $cache = self::setTrial();

        return $cache;
    }

    public static function sendToGroupByBot ($group, $text, $photos = null) { // TODO: Отправлять всем пользователям бота, а не в группы
        $token = env("TELEGRAM_BOT_TOKEN");
        $resultArray = null;
        try {
            $options = [];
            if ($photos) {
                if (is_string($photos)) $photos = json_decode($photos, 1);

                $media = [];
                $http = Http::withOptions($options);

                foreach ($photos as $i => $item) {
                    if (isset($item['file_id'])) {
                        $type = ($item['type'] === 'image') ? 'photo' : $item['type'];
                        $mediaItem = [
                            'type' => $type,
                            'media' => $item['file_id']
                        ];
                    } else {
                        $mimeType = Storage::disk("public")->mimeType($item);
                        $isImage = strpos($mimeType, "image/") === 0;
                        $isVideo = strpos($mimeType, "video/") === 0;

                        if ($isImage) $type = 'photo';
                        elseif ($isVideo) $type = 'video';
                        else continue;

                        $attachName = "attach" . $i;
                        $mediaItem = [
                            'type' => $type,
                            'media' => "attach://{$attachName}"
                        ];
                        $http = $http->attach($attachName, Storage::disk("public")->get($item), basename($item));
                    }


                    if ($i === 0 && $text) {
                        $mediaItem['caption'] = $text;
                        $mediaItem['parse_mode'] = 'HTML';
                    }
                    $media[] = $mediaItem;
                }

                $response = $http->post('https://api.telegram.org/bot' . $token . '/sendMediaGroup', [
                    'chat_id' => $group,
                    'media' => json_encode($media)
                ]);

                $json = $response->json();
                Log::critical($json);

                if (isset($json['result']) && is_array($json['result'])) {
                    foreach ($json['result'] as $item) {
                        if (isset($item['photo'])) {
                            $lastPhoto = end($item['photo']);
                            $resultArray[] = [
                                "file_id" => $lastPhoto['file_id'],
                                "type" => "image"
                            ];
                        } elseif (isset($item['video'])) {
                            $resultArray[] = [
                                "file_id" => $item['video']['file_id'],
                                "type" => "video"
                            ];
                        }
                    }
                }
            } else {
                $data = [
                    'chat_id' => $group,
                    'text' => $text,
                    'parse_mode' => 'HTML',
                ];

                $response = Http::withOptions($options)
                    ->post('https://api.telegram.org/bot' . $token . '/sendMessage', $data);
            }
            Log::critical($response->json());
        } catch (Exception $e) {
            Log::critical($e->getMessage());
        }

        return $resultArray;
    }

    public static function validateTelegramAttachment($file, $maxSizeBytes = 10 * 1024 * 1024, $maxWidth = 10000, $maxHeight = 10000)
    {
        // Список разрешённых расширений и mime-типов
        $allowedExtensions = [
            'jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp', 'tiff',
            'mp4', 'mov', 'avi', 'mkv', 'webm', 'wmv', 'flv', 'mpeg', '3gp'
        ];

        $allowedMimeTypes = [
            // изображения
            'image/jpeg',
            'image/png',
            'image/gif',
            'image/webp',
            'image/bmp',
            'image/tiff',
            // видео
            'video/mp4',
            'video/x-m4v',
            'video/quicktime',
            'video/x-msvideo',
            'video/x-matroska',
            'video/webm',
            'video/x-ms-wmv',
            'video/x-flv',
            'video/mpeg',
            'video/3gpp',
            'video/avi'
        ];

        // Проверка на наличие файла
        if (!$file || !$file->isValid()) {
            throw new Exception('Не загружен файл.');
        }

        // Получаем расширение и mime-тип
        $extension = strtolower($file->getClientOriginalExtension());
        $mimeType = $file->getMimeType();

        if (!in_array($extension, $allowedExtensions) || !in_array($mimeType, $allowedMimeTypes)) {
            throw new Exception('Формат файла не поддерживается. Допустимы: jpeg, jpg, png, gif, webp, bmp, tiff.');
        }

        // Проверка размера файла (в байтах)
        if ($file->getSize() > $maxSizeBytes) {
            throw new Exception('Файл слишком большой. Максимальный размер: ' . number_format($maxSizeBytes / 1024 / 1024, 2) . ' МБ');
        }

        $allowedImageMimeTypes = [
            'image/jpeg',
            'image/png',
            'image/gif',
            'image/webp',
            'image/bmp',
            'image/tiff',
        ];
        if (in_array($file->getMimeType(), $allowedImageMimeTypes)) {
            // Это картинка — проверяем размеры
            $imageSize = getimagesize($file->getPathname());
            if (!$imageSize) {
                throw new Exception('Не удалось получить размеры изображения.');
            }

            [$width, $height] = $imageSize;
            if ($width + $height > $maxHeight) {
                throw new Exception("Изображение слишком большое по габаритам. Допустимые: высота + ширина = 10.000 пикселей");
            }
        }

        return true;
    }
}
