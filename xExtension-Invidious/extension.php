<?php

/**
 * Class InvidiousExtension
 *
 * Based on https://github.com/Korbak/freshrss-invidious
 * With extensions from https://github.com/cn-tools/freshrss-invidious
 * Latest version can be found at https://github.com/tunbridgep/freshrss-invidious
 *
 * @author Paul Tunbridge forking Korbak forking Kevin Papst
 */
class InvidiousExtension extends Minz_Extension
{
    /**
     * Video player width
     * @var int
     */
    protected $width = 560;
    /**
     * Video player height
     * @var int
     */
    protected $height = 315;
    /**
     * Whether we display the original feed content
     * @var bool
     */
    protected $showContent = false;
    /**
     * Invidious instance to use
     * @var string
     */
    protected $instance = 'yewtu.be';
    /**
     * The text for the YouTube link
     * @var string
     */
    protected $youtube_link_text = 'Youtube Link';
    
    /**
     * Enable globally replacing Youtube embeds
     * @var bool
     */
    protected $replace_global = false;

    /**
     * Initialize this extension
     */
    public function init()
    {
        // Make sure to not run on server without libxml
        if (!extension_loaded('xml')) {
            return;
        }
    
        // entry_before_insert only fires for new entries being fetched; already-stored
        // entries are never processed. entry_before_display runs at render time so all
        // existing entries work immediately without a re-fetch.
        // Minz_HookType enum was added in FreshRSS 1.28; fall back to string on older builds.
        if (class_exists('Minz_HookType')) {
            $this->registerHook(Minz_HookType::EntryBeforeDisplay, array($this, 'handleInvidious'));
        } else {
            $this->registerHook('entry_before_display', array($this, 'handleInvidious'));
        }
        $this->registerTranslates();
    }

    /**
     * Initializes the extension configuration, if the user context is available.
     * Do not call that in your extensions init() method, it can't be used there.
     */
    public function loadConfigValues()
    {
        if (!class_exists('FreshRSS_Context', false) || null === FreshRSS_Context::$user_conf) {
            return;
        }

        if (FreshRSS_Context::$user_conf->in_player_width != '') {
            $this->width = FreshRSS_Context::$user_conf->in_player_width;
        }
        if (FreshRSS_Context::$user_conf->in_player_height != '') {
            $this->height = FreshRSS_Context::$user_conf->in_player_height;
        }
        if (FreshRSS_Context::$user_conf->in_show_content != '') {
            $this->showContent = (bool)FreshRSS_Context::$user_conf->in_show_content;
        }
        if (FreshRSS_Context::$user_conf->in_replace_global != '') {
            $this->replace_global = (bool)FreshRSS_Context::$user_conf->in_replace_global;
        }
        if (FreshRSS_Context::$user_conf->in_player_instance != '') {
            $this->instance = FreshRSS_Context::$user_conf->in_player_instance;
            $this->sanitizeInstanceURL();
        }
        $this->youtube_link_text = _t('ext.in_videos.youtube_link_text');
    }

    public function appendYoutubeLink($html,$link)
    {
        if ($this->showContent) {
            $html .= $this->getNiceYoutubeLinkText($link);
        }
        return $html;
    }

    public function handleInvidious($entry)
    {
        $this->loadConfigValues();
        $link = $entry->link();
        
        //We have an invidious link (aka an invidious feed was already added manually)
        //We simply need to add the video embed, and remove the thumbnail image from the description
        if ($this->isInvidiousURL($link))
        {
            $embed_link = $this->getEmbedLink($link);
            $html = $this->getIFrameHtml($embed_link);
            $html = $this->appendYoutubeLink($html, $link);
            // strip_tags removes any thumbnail images and links that come from the feed,
            // leaving only the plain description text.
            $desc = trim(strip_tags($entry->content() ?? ''));
            if ($desc !== '') {
                $html .= '<p>' . $desc . '</p>';
            }
            
            $entry->_content($html);
        }
        
        //We have a youtube link
        //We need to embed the invidious video and show the description from the feed
        else if ($this->isYoutubeURL($link))
        {
            $invidious_link = $this->getInstanceLinkFromYoutubeLink($link);
            $embed_link = $this->getEmbedLink($invidious_link);
            $html = $this->getIFrameHtml($embed_link);
            $html = $this->appendYoutubeLink($html, $link);
            // strip_tags removes any HTML from the stored feed content (including any
            // back-links to youtube.com that YouTube embeds in the description).
            $desc = trim(strip_tags($entry->content() ?? ''));
            if ($desc !== '') {
                $html .= '<p>' . $desc . '</p>';
            }
            
            $entry->_content($html);
        }
        
        //We are not a Youtube or Invidious URL, but we should still handle any youtube embeds
        else if ($this->replace_global)
        {
            $html = $entry->content();
            $html = $this->replaceYoutubeEmbeds($html);
            
            $entry->_content($html);
        }
     
        return $entry;        
    }
    
    private function replaceYoutubeEmbeds(string $html) : string
    {   
        
        libxml_use_internal_errors(true);
        $article = new DOMDocument;
        $article->validateOnParse = true;
        $article->loadHTML($html);
        libxml_use_internal_errors(false);
        
        //fix embeds
        $iframes = $article->getElementsByTagName('iframe');
        foreach ($iframes as $iframe)
        {
            $src = $iframe->getAttribute("src");
            if ($this->isYoutubeURL($src))
            {
                $src = $this->getInstanceLinkFromYoutubeLink($src);
                $iframe->setAttribute('src',$src);
            }
        }
        
        //fix links
        $links = $article->getElementsByTagName('a');
        foreach ($links as $link)
        {
            $href = $link->getAttribute("href");
            if ($this->isYoutubeURL($href))
            {
                $href = $this->getInstanceLinkFromYoutubeLink($href);
                $link->setAttribute('href',$href);
            }
        }

        $body = $article->getElementsByTagName('body')->item(0);
        if ($body === null) {
            return $html;
        }
        $result = '';
        foreach ($body->childNodes as $child) {
            $result .= $article->saveHTML($child);
        }
        return $result;
    }

    private function isYoutubeURL(string $url): bool
    {
        $url_info = parse_url($url);
        $hostname = $url_info['host'] ?? '';
        $hostname = str_replace("www.","",$hostname);
            
        return $hostname == 'youtube.com' || $hostname == 'youtube-nocookie.com';
    }

    //Check if the base URL of an entry is an invidious URL
    private function isInvidiousURL(string $url): bool 
    {
        $url_info = parse_url($url);
        $hostname = $url_info['host'] ?? '';
        $hostname = str_replace("www.","",$hostname);
        
        return $hostname == $this->instance;
    }
    
    //Get the embed link for an invidious video
    private function getEmbedLink(string $invidious_url): string
    {
        $remove_watch = str_replace("watch?v=","",$invidious_url);
        return str_replace($this->instance,$this->instance."/embed",$remove_watch);
    }

    //Get a formatted "Watch on Youtube" link
    private function getNiceYoutubeLinkText(string $link)
    {
        // Convert an Invidious URL back to a youtube.com URL for the link.
        $yt_url = str_replace($this->instance, "youtube.com", $link);
    
        return '<p><a target="_blank" rel="noreferrer" href="'.$yt_url.'">'.$this->youtube_link_text.'</a></p>';
    }
    
    //Get an invidious link from our youtube link
    private function getInstanceLinkFromYoutubeLink(string $youtube_url): string
    {
        // Single regex handles all variants in one pass:
        //   https://www.youtube.com/...
        //   https://youtube.com/...
        //   https://www.youtube-nocookie.com/...   ← privacy-enhanced embeds
        //   https://youtube-nocookie.com/...
        //   //www.youtube.com/...                  ← protocol-relative
        // The www. is consumed by the optional (?:www\.)? group so it is never
        // left orphaned on the front of the instance hostname.
        $instance = $this->instance;
        $result = preg_replace_callback(
            '#^(https?://|//)(?:www\.)?(?:youtube\.com|youtube-nocookie\.com)#i',
            function ($m) use ($instance) { return $m[1] . $instance; },
            $youtube_url
        );
        return $result ?? $youtube_url;
    }
    
    /**
     * Returns an HTML <iframe> for a given URL for the configured width and height.
     *
     * @param string $url
     * @return string
     */
    private function getIFrameHtml($url)
    {
    
        return '<iframe 
                style="height: ' . $this->height . 'px; width: ' . $this->width . 'px;" 
                width="' . $this->width . '" 
                height="' . $this->height . '" 
                src="' . $url . '" 
                frameborder="0" 
                allowfullscreen></iframe>';
    }
    
    //removes everything but the basename from the instance URL, as well as the trailing slash
    private function sanitizeInstanceURL()
    {
        $url_info = parse_url($this->instance);
        $hostname = $url_info['host'] ?? '';
        if ($hostname != "")
            $this->instance = $hostname;
    }

    /*
     * fetch the video description
     */
     protected function getVideoDescriptionFromInstance($link)
     {            
        //Youtube delivers no textual content with it's videos - we will have to fetch it
        libxml_use_internal_errors(true);
        $instance_page = file_get_contents($link);
        $page = new DOMDocument;
        $page->validateOnParse = true;
        $page->loadHtml($instance_page);
        $desc_element = $page->getElementById('descriptionWrapper');
        libxml_use_internal_errors(false);
        
        return $desc_element->textContent;
     }

    /*
     * fetch the video description
     */
    protected function getVideoDescriptionFromFeed($entry)
    {
        libxml_use_internal_errors(true);
        $article = new DOMDocument;
        $article->validateOnParse = true;
        $article->loadHTML($entry->content());
        libxml_use_internal_errors(false);
        
        return $article->textContent;
    }

    /**
     * Saves the user settings for this extension.
     */
    public function handleConfigureAction()
    {
        $this->registerTranslates();
        $this->loadConfigValues();

        if (Minz_Request::isPost()) {
            FreshRSS_Context::$user_conf->in_player_height = (int)Minz_Request::param('in_height', '');
            FreshRSS_Context::$user_conf->in_player_width = (int)Minz_Request::param('in_width', '');
            FreshRSS_Context::$user_conf->in_show_content = (int)Minz_Request::param('in_show_content', 0);
            FreshRSS_Context::$user_conf->in_player_instance = (string)Minz_Request::param('in_instance', '');
            FreshRSS_Context::$user_conf->in_replace_global = (bool)Minz_Request::param('in_replace_global', '');
            FreshRSS_Context::$user_conf->save();
        }
    }
}
