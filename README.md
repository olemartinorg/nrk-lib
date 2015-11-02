# nrk-lib
This is a simple library for accessing TV content (categories, series, seasons and episodes) from the Norwegian channel NRK, written in PHP.

This is just something i threw together an evening. I'm probably going to use this in some other project later on. Don't consider this feature-complete just yet.

#### Example usage

This just loops all categories and shows (can either be a single "episode", or a series with seasons and multiple episodes). Please don't run this simple example, though - it will hammer NRKs servers.

```php
<?php

    include 'nrk-lib.php';

    $categories = NRK\NRK::getCategories();

    foreach($categories as $category) {
        echo $category."\n";
        $shows = $category->getShows();

        foreach ($shows as $show) {
            echo "    ".$show."\n";

            if($show instanceof NRK\Series) {
                foreach ($show->getSeasons() as $season) {
                    echo "        ".$season."\n";
                    foreach ($season->getEpisodes() as $episode) {
                        echo "            ".$episode."\n";
                    }
                }
                foreach ($show->getRecentEpisodes() as $episode) {
                    echo "        ".$episode."\n";
                }
            }
        }
    }

```

The library uses a simple serialized file as a cache, just to keep from requesting the server over and over again. If you want to disable this, do a ```NRK\Cache::disable(); ```.
