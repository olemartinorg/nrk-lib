<?php

    namespace NRK;

    class NRK {

        const BASE_URL = 'https://tv.nrk.no';

        /**
         * @return Category[]
         */
        public static function getCategories() {
            if(Cache::get('categories')) {
                return Cache::get('categories');
            }

            $html = Tools::fetch(self::BASE_URL.'/programmer');
            preg_match_all('#<a class="drilldown-link" href="/programmer/(.+?)">(.+?)</a>#s', $html, $matches);

            $categories = array();
            foreach ($matches[1] as $index => $category) {
                if(strpos($category, '/') !== false) {
                    continue;
                }

                $categories[] = new Category($category, trim(strip_tags($matches[2][$index])));
            }

            Cache::set('categories', $categories, 4 * Cache::WEEKS);

            return $categories;
        }

    }

    class Cache {

        const HOURS = 3600;
        const DAYS = 86400;
        const WEEKS = 604800;

        private static $disabled = false;
        private static $cache;
        private static $cacheFile = "cache.dat";

        public function disable() {
            self::$cache = null;
            self::$disabled = true;
        }
        
        private static function load() {
            if(self::$cache === null && !self::$disabled) {
                if (file_exists(self::$cacheFile)) {
                    self::$cache = unserialize(file_get_contents(self::$cacheFile));
                } else {
                    self::$cache = array();
                }
                register_shutdown_function(array('\\NRK\\Cache', 'save'));
            }
        }

        public static function save() {
            if(self::$cache !== null && !self::$disabled) {
                file_put_contents(self::$cacheFile, serialize(self::$cache));
            }
        }

        public static function get($key, $default=null) {
            self::load();
            if(!isset(self::$cache[$key])) {
                return $default;
            }
            if(isset(self::$cache[$key]['eol']) && self::$cache[$key]['eol'] < time()) {
                return $default;
            }

            return unserialize(self::$cache[$key]['val']);
        }

        public static function set($key, $value, $ttl=null) {
            self::load();
            if(self::$disabled) {
                return;
            }
            if($ttl) {
                $ttl = time() + $ttl;
            }
            self::$cache[$key] = array(
                'eol' => $ttl,
                'val' => serialize($value),
            );
        }
    }

    class Tools {

        public static function fetch($url) {
            $curl = curl_init();
            curl_setopt($curl, CURLOPT_URL, $url);
            curl_setopt($curl, CURLOPT_RETURNTRANSFER, 1);
            return curl_exec($curl);
        }

        public static function fetchJson($url) {
            return json_decode(self::fetch($url), true);
        }

        public static function parseEpisodes($html) {
            preg_match_all('#<li class="episode-item.*?" data-episode=".+?">.+?</li>#s', $html, $matches);

            $episodes = array();
            foreach ($matches[0] as $index => $episodeHtml) {
                preg_match('#href="(.*?)"#', $episodeHtml, $urlMatch);
                $url = $urlMatch[1];

                preg_match('#<h3.*?>[^<>]+#', $episodeHtml, $titleMatch);

                $episodes[] = new Episode($url, trim(strip_tags($titleMatch[0])));
            }

            return $episodes;
        }

    }

    class Category {

        const BASE_PATH = '/programmer';

        private $id;
        private $title;

        public function __construct($id, $title) {
            $this->id = $id;
            $this->title = $title;
        }

        public function __toString() {
            return sprintf('Category: %s (%s)', $this->title, $this->id);
        }

        public function getTitle() {
            return $this->title;
        }

        public function getUrl() {
            return NRK::BASE_URL.self::BASE_PATH.'/'.$this->id;
        }

        /**
         * @return Show[]
         */
        public function getShows() {
            if(Cache::get('shows-'.$this->id)) {
                return Cache::get('shows-'.$this->id);
            }

            $page = 0;
            $shows = array();

            while(true) {
                $json = Tools::fetchJson("https://tv.nrk.no/listobjects/indexelements/{$this->id}/page/{$page}");
                $page++;

                if(!$json) {
                    break;
                }

                foreach ($json['data']['characters'] as $char) {
                    foreach ($char['elements'] as $element) {
                        if(strpos($element['url'], '/serie') === 0) {
                            $obj = new Series($element['url'], $element['title']);
                        } else {
                            $obj = new Episode($element['url'], $element['title']);
                        }
                        foreach ($element['images'] as $image) {
                            $obj->addImage($image['imageUrl']);
                        }

                        $shows[] = $obj;
                    }
                }
            }

            Cache::set('shows-'.$this->id, $shows, 1 * Cache::DAYS);

            return $shows;
        }
    }

    abstract class Show {

        protected $url;
        protected $title;
        protected $images = array();
        private $html;

        public function __construct($url, $title) {
            $this->url = $url;
            $this->title = $title;
        }

        public function __toString() {
            $reflection = new \ReflectionClass($this);
            return sprintf("%s: %s", $reflection->getShortName(), $this->title);
        }

        public function addImage($url) {
            $this->images[] = $url;
        }

        public function getTitle() {
            return $this->title;
        }

        public function getUrl() {
            return NRK::BASE_URL.$this->url;
        }

        protected function getHtml() {
            if($this->html === null) {
                $this->html = Tools::fetch($this->getUrl());
            }

            return $this->html;
        }

        public function getImages() {
            return $this->images;
        }

    }

    class Series extends Show {

        /**
         * @return Season[]
         */
        public function getSeasons() {
            if(Cache::get('seasons-'.$this->url)) {
                return Cache::get('seasons-'.$this->url);
            }

            $html = $this->getHtml();
            preg_match_all('#<a[^<>]+?href="(/program/Episodes/.+?/\d+?)".*?</a>#s', $html, $matches);

            $seasons = array();
            foreach ($matches[0] as $index => $link) {
                $seasons[] = new Season($this, trim(strip_tags($link)), $matches[1][$index]);
            }

            Cache::set('seasons-'.$this->url, $seasons, 1 * Cache::WEEKS);

            return $seasons;
        }

        /**
         * @return Episode[]
         */
        public function getRecentEpisodes() {
            if(Cache::get('recent-episodes-'.$this->url)) {
                return Cache::get('recent-episodes-'.$this->url);
            }

            $episodes = Tools::parseEpisodes($this->getHtml());
            Cache::set('recent-episodes-'.$this->url, $episodes, 1 * Cache::DAYS);
            return $episodes;
        }

    }

    class Season {

        private $series;
        private $title;
        private $url;

        public function __construct(Series $series, $title, $url) {
            $this->series = $series;
            $this->title = $title;
            $this->url = $url;
        }

        public function __toString() {
            return sprintf('Season: %s', $this->title);
        }

        public function getTitle() {
            return $this->title;
        }

        public function getUrl() {
            return NRK::BASE_URL.$this->url;
        }

        public function getEpisodes() {
            if(Cache::get('episodes-for-season-'.$this->url)) {
                return Cache::get('episodes-for-season-'.$this->url);
            }

            $episodes = Tools::parseEpisodes(Tools::fetch($this->getUrl()));
            Cache::set('episodes-for-season-'.$this->url, $episodes, 1 * Cache::DAYS);
            return $episodes;
        }
    }


    class Episode extends Show {

        public function getUniqueId() {
            $parts = explode('/', $this->url);
            if(count($parts) >= 4 && $parts[1] === "serie") {
                return $parts[3];
            } elseif(count($parts) >= 3 && $parts[1] === "program") {
                return $parts[2];
            }

            return null;
        }

        public function __toString() {
            return sprintf("%s (%s)", parent::__toString(), $this->getUniqueId());
        }

    }
