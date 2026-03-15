/**
 * BKCS 音乐播放器核心逻辑 (Optimized 2026)
 * 修复：异步加载冲突、Mixed Content、歌词解析逻辑、API字段异构兼容
 */
document.addEventListener('DOMContentLoaded', function() {
    // 1. 基础 UI 元素初始化
    const audio = document.getElementById('audio-player');
    const lyricsList = document.getElementById('lyrics-list');
    const playBtn = document.getElementById('play-btn');
    const albumArt = document.getElementById('album-art');
    const bgLayer = document.getElementById('bg-layer');
    const playlistArea = document.getElementById('playlist-area');
    const playlistOverlay = document.getElementById('playlist-overlay');
    const playlistToggleBtn = document.getElementById('playlist-toggle-btn');
    const closePlaylistBtn = document.getElementById('close-playlist-btn');

    // 2. 配置与状态
    const PROXY_URL = '/proxy.php'; 
    const DEFAULT_IMG = "https://p2.music.126.net/6y-UleORITEDbvrOLV0Q8A==/5639395138885805.jpg";
    
    let playlist = [];
    let currentIndex = 0;
    let isPlaying = false;
    let lyricData = [];
    let currentLoadToken = 0; // 核心：用于防止切歌过快导致旧歌词覆盖新歌词

    // 3. 补全 Favicon 防止 404 报错
    if (!document.querySelector("link[rel~='icon']")) {
        const link = document.createElement('link');
        link.rel = 'icon';
        link.href = 'data:;base64,iVBORw0KGgo=';
        document.head.appendChild(link);
    }

    // 4. API 请求封装 (增强版：加入 60 秒前端超时控制)
    async function fetchApi(endpoint, params = {}) {
        // 创建一个 60 秒的超时控制器
        const controller = new AbortController();
        const timeoutId = setTimeout(() => controller.abort(), 60000);

        try {
            const query = new URLSearchParams(params).toString();
            const fullPath = endpoint + (query ? '?' + query : '');
            
            // 通过本地 proxy.php 代理，解决跨域和 Cookie 验证问题
            const res = await fetch(`${PROXY_URL}?path=${encodeURIComponent(fullPath)}`, {
                method: 'GET',
                headers: { 'Accept': 'application/json' },
                signal: controller.signal // 绑定超时控制器
            });
            
            clearTimeout(timeoutId); // 请求成功，清除定时器

            if (!res.ok) throw new Error(`HTTP ${res.status}`);
            
            const json = await res.json();
            if (json.code === 500 || json.code === 403) throw new Error(json.msg || '服务器错误');
            return json;
        } catch (e) {
            clearTimeout(timeoutId); // 发生错误，清除定时器
            if (e.name === 'AbortError') {
                console.error("Fetch API Error: 前端请求已等待超过 60 秒被主动取消");
                throw new Error('前端请求超时，请刷新重试');
            }
            console.error("Fetch API Error:", e);
            throw e;
        }
    }

    // 5. 辅助函数：格式化歌手和封面 (增强兼容版)
    function getArtist(t) {
        // 兼容部分 API 将数据嵌套在 song 对象中的情况
        const data = t.song ? t.song : t;
        
        // 覆盖所有可能的歌手字段 (网易原生: ar/artists, 聚合API: author/singer)
        const ar = data.ar || data.artists || data.singer || data.author;
        
        // 如果是数组格式 (如 [{name: "周杰伦"}, {name: "林俊杰"}])
        if (Array.isArray(ar)) {
            const names = ar.map(a => {
                if (typeof a === 'object') {
                    // 深度兼容数组内部的属性名
                    return a.name || a.singer || a.author || '';
                }
                return a;
            }).filter(Boolean); // 过滤掉无效的空值
            
            if (names.length > 0) return names.join(' / ');
        }
        
        // 如果 API 直接返回了用逗号/斜杠拼接好的字符串
        if (typeof ar === 'string' && ar.trim() !== '') {
            return ar;
        }
        
        // 兜底基础字段，如果全部脱靶则返回"未知歌手"
        return data.artist || data.author || data.singer || "未知歌手";
    }

    function getPic(t) { 
        const data = t.song ? t.song : t;
        // 增加对 cover、pic 等常见第三方 API 封面字段的兼容，防止后续封面也不显示
        let pic = (data.al?.picUrl || data.album?.picUrl || data.picUrl || data.cover || data.pic) || DEFAULT_IMG; 
        return pic.replace('http://', 'https://'); // 强制 HTTPS
    }

    // 6. UI 更新逻辑
    function setLyricMessage(msg) {
        if (!lyricsList) return;
        lyricsList.innerHTML = `<li class="lyric-line active">${msg}</li>`;
        const maskH = document.querySelector('.lyrics-mask')?.offsetHeight || 200;
        lyricsList.style.transform = `translateY(${(maskH / 2) - 20}px)`;
    }

    function updateUI() {
        if (playBtn) playBtn.innerHTML = isPlaying ? '<i class="fas fa-pause"></i>' : '<i class="fas fa-play"></i>';
        if (albumArt) isPlaying ? albumArt.classList.add('playing') : albumArt.classList.remove('playing');
        
        // 激活列表项样式
        document.querySelectorAll('.track-item').forEach(el => el.classList.remove('active'));
        const activeItem = document.getElementById(`item-${currentIndex}`);
        if(activeItem) activeItem.classList.add('active');
    }

    // 7. 核心播放函数
    async function loadSong(index, shouldPlay) {
        if (!playlist[index]) return;
        
        currentIndex = index;
        const track = playlist[index];
        const myToken = ++currentLoadToken; // 生成本次加载的唯一标识

        // 停止当前播放并重置状态
        audio.pause();
        audio.removeAttribute('src');
        isPlaying = false;
        lyricData = [];
        updateUI();

        // 基础信息展示
        document.getElementById('track-name').textContent = track.name || '未知曲目';
        document.getElementById('track-artist').textContent = getArtist(track);
        const pic = getPic(track);
        if (albumArt) albumArt.src = pic + "?param=500y500";
        if (bgLayer) bgLayer.style.backgroundImage = `url(${pic}?param=800y800)`;
        
        setLyricMessage('音源解析中...');

        try {
            // A. 获取音源地址
            const sJson = await fetchApi('/song', { id: track.id });
            if (myToken !== currentLoadToken) return; // 如果已经切歌，放弃后续逻辑

            let songUrl = sJson.data?.[0]?.url || sJson.data?.url || sJson.url;
            
            // 版权/会员判定
            if (!songUrl || songUrl === "null") {
                setLyricMessage('⚠️ 无版权或需 VIP 会员');
                return;
            }

            // 解决 Mixed Content (HTTP 音源在 HTTPS 页面无法播放)
            audio.src = songUrl.replace('http://', 'https://');
            
            if (shouldPlay) {
                const playPromise = audio.play();
                if (playPromise !== undefined) {
                    playPromise.then(() => {
                        if (myToken === currentLoadToken) {
                            isPlaying = true;
                            updateUI();
                        }
                    }).catch(e => {
                        if (myToken === currentLoadToken) setLyricMessage('播放受限（需交互）');
                    });
                }
            }

            // B. 获取并解析歌词
            setLyricMessage('加载歌词...');
            const lJson = await fetchApi('/lyric', { id: track.id });
            if (myToken !== currentLoadToken) return;

            let lrcString = lJson.lrc?.lyric || lJson.data?.lrc?.lyric || lJson.lyric || "";
            
            if (lJson.nolyric || lJson.uncollected) {
                setLyricMessage('纯音乐，请欣赏');
            } else if (lrcString) {
                parseLyrics(lrcString);
            } else {
                setLyricMessage('暂无歌词');
            }

        } catch (err) {
            if (myToken === currentLoadToken) {
                console.error(err);
                setLyricMessage('❌ 加载失败，请检查网关');
            }
        }
    }

    // 8. 歌词解析逻辑
    function parseLyrics(lrc) {
        lyricData = [];
        const lines = lrc.split('\n');
        const pattern = /\[(\d{2}):(\d{2})\.(\d{2,3})\]/;

        lines.forEach(line => {
            const match = pattern.exec(line);
            if (match) {
                const m = parseInt(match[1]);
                const s = parseInt(match[2]);
                const ms = parseInt(match[3]);
                const time = m * 60 + s + (ms > 99 ? ms / 1000 : ms / 100);
                const text = line.replace(pattern, '').trim();
                if (text) lyricData.push({ time, text });
            }
        });

        lyricData.sort((a, b) => a.time - b.time);
        
        if (lyricData.length > 0) {
            lyricsList.innerHTML = lyricData.map(l => `<li class="lyric-line">${l.text}</li>`).join('');
        } else {
            setLyricMessage('歌词格式暂不支持');
        }
    }

    // 9. 歌词滚动监听
    audio.addEventListener('timeupdate', () => {
        if (!lyricData.length || !lyricsList) return;
        const currentTime = audio.currentTime;
        
        // 寻找当前播放到的歌词行
        const idx = lyricData.findIndex((l, i) => 
            currentTime >= l.time && (!lyricData[i+1] || currentTime < lyricData[i+1].time)
        );

        if (idx !== -1) {
            const lines = lyricsList.querySelectorAll('.lyric-line');
            if (lines[idx] && !lines[idx].classList.contains('active')) {
                lyricsList.querySelector('.active')?.classList.remove('active');
                lines[idx].classList.add('active');
                
                // 计算居中偏移量
                const mask = document.querySelector('.lyrics-mask');
                const offset = (mask.offsetHeight / 2) - lines[idx].offsetTop - (lines[idx].offsetHeight / 2);
                lyricsList.style.transform = `translateY(${offset}px)`;
            }
        }
    });

    // 10. 初始化加载列表
    async function init() {
        if (typeof PLAYLIST_ID === 'undefined' || !PLAYLIST_ID) {
            document.getElementById('loading-text').innerText = "未配置歌单ID";
            return;
        }

        try {
            const json = await fetchApi('/playlist', { id: PLAYLIST_ID });
            const tracks = json.data?.playlist?.tracks || json.playlist?.tracks || [];
            
            if (tracks.length === 0) throw new Error("歌单为空");

            playlist = tracks;
            document.getElementById('p-count').textContent = `共 ${playlist.length} 首`;
            
            const itemsHtml = playlist.map((t, i) => `
                <div class="track-item" id="item-${i}" onclick="window.playSong(${i}, true)">
                    <div class="track-num">${i + 1}</div>
                    <img src="${getPic(t)}?param=50y50" class="track-thumb" onerror="this.src='${DEFAULT_IMG}'">
                    <div class="track-details">
                        <div class="item-title">${t.name || '未知曲目'}</div>
                        <div class="item-artist">${getArtist(t)}</div>
                    </div>
                </div>
            `).join('');
            
            document.getElementById('p-items').innerHTML = itemsHtml;
            document.getElementById('loading-layer').style.display = 'none';
            
            // 默认加载第一首，不自动播放
            loadSong(0, false);

        } catch (e) {
            document.getElementById('loading-text').innerText = "获取歌单失败: " + e.message;
        }
    }

    // 11. 交互控制
    window.playSong = (index, shouldPlay) => {
        loadSong(index, shouldPlay);
        if (window.innerWidth <= 1024) toggleMobilePlaylist();
    };

    if (playBtn) playBtn.onclick = () => {
        if (!audio.src) return;
        if (isPlaying) audio.pause(); else audio.play();
        isPlaying = !isPlaying;
        updateUI();
    };

    document.getElementById('next-btn').onclick = () => window.playSong((currentIndex + 1) % playlist.length, true);
    document.getElementById('prev-btn').onclick = () => window.playSong((currentIndex - 1 + playlist.length) % playlist.length, true);
    audio.onended = () => document.getElementById('next-btn').click();

    // 播放列表 UI 切换
    function toggleMobilePlaylist() {
        if (!playlistArea || !playlistOverlay) return;
        playlistArea.classList.toggle('show');
        playlistOverlay.classList.toggle('show');
    }
    
    if (playlistToggleBtn) playlistToggleBtn.onclick = toggleMobilePlaylist;
    if (closePlaylistBtn) closePlaylistBtn.onclick = toggleMobilePlaylist;
    if (playlistOverlay) playlistOverlay.onclick = toggleMobilePlaylist;

    init();
});