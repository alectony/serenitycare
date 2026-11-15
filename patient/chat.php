<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/layout.php';

$me = require_role('patient');
$uid = $me['user']['id'];
$token = $me['token'];

// Fetch available verified counselors to chat with
$cr = sb_request('GET', 'profiles', 'select=id,full_name,email&role=eq.counselor&is_active=eq.true&order=full_name.asc', null, $token);
$counselors = ($cr['status'] === 200 && is_array($cr['data'])) ? $cr['data'] : [];

// Selected counselor
$activeCounselorId = $_GET['with'] ?? ($counselors[0]['id'] ?? '');
$activeCounselor = null;
foreach ($counselors as $c) {
    if ($c['id'] === $activeCounselorId) {
        $activeCounselor = $c;
        break;
    }
}

// Generate Google Meet Link for quick online consultation
$meetLink = generate_google_meet_link($uid . $activeCounselorId);

$rightHeader = patient_header($uid, $token);
page_start('patient', 'Consultation Messages', '/patient/chat.php', 'Counseling Messages', 'Chat privately with your guidance counselors in a safe, encrypted space.', $rightHeader);
?>
            <div class="chat-container">
                <!-- Sidebar: Counselors List -->
                <aside class="chat-sidebar">
                    <div class="chat-sidebar-header">
                        <h3>Guidance Counselors</h3>
                        <small>Direct confidential messaging</small>
                    </div>
                    <div class="chat-user-list">
                        <?php if (empty($counselors)): ?>
                            <p class="empty" style="padding:16px;">No counselors available right now.</p>
                        <?php else: foreach ($counselors as $c): 
                            $isActive = ($c['id'] === $activeCounselorId);
                            $ini = '';
                            foreach (array_slice(explode(' ', $c['full_name']), 0, 2) as $w) { $ini .= strtoupper(substr($w, 0, 1)); }
                        ?>
                            <a href="/patient/chat.php?with=<?php echo urlencode($c['id']); ?>" class="chat-user-item <?php echo $isActive ? 'active' : ''; ?>">
                                <div class="profile-avatar" style="width:38px;height:38px;font-size:14px;"><?php echo e($ini); ?></div>
                                <div class="chat-user-info">
                                    <strong><?php echo e($c['full_name']); ?></strong>
                                    <small>Guidance Counselor</small>
                                </div>
                                <span class="online-indicator" title="Online Consultation Available"></span>
                            </a>
                        <?php endforeach; endif; ?>
                    </div>
                </aside>

                <!-- Main Chat Pane -->
                <section class="chat-main">
                    <?php if ($activeCounselor): ?>
                        <div class="chat-header">
                            <div class="chat-header-user">
                                <div class="profile-avatar" style="width:42px;height:42px;"><?php echo strtoupper(substr($activeCounselor['full_name'], 0, 1)); ?></div>
                                <div>
                                    <h3 style="margin:0;font-size:16px;"><?php echo e($activeCounselor['full_name']); ?></h3>
                                    <small style="color:var(--success);font-weight:600;">&bull; Ready for Student Consultation</small>
                                </div>
                            </div>
                            <div class="chat-header-actions">
                                <a href="<?php echo e($meetLink); ?>" target="_blank" rel="noopener noreferrer" class="btn btn-sm" style="display:inline-flex;align-items:center;gap:6px;background:var(--surface);border-color:var(--primary);color:var(--primary);" title="Launch Google Meet Video Call">
                                    <svg class="icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="color:#00897B;"><polygon points="23 7 16 12 23 17 23 7"/><rect x="1" y="5" width="15" height="14" rx="2" ry="2"/></svg>
                                    <span>Google Meet</span>
                                </a>
                                <a href="/patient/appointment.php?counselor_id=<?php echo urlencode($activeCounselor['id']); ?>" class="btn-sm btn-primary">Book Session</a>
                            </div>
                        </div>

                        <!-- Messages Stream -->
                        <div class="chat-messages" id="chatMessages">
                            <div class="chat-loading" id="chatLoading">Loading confidential message history...</div>
                        </div>

                        <!-- Chat Input Box -->
                        <div class="chat-input-bar">
                            <div id="chatError" role="alert" style="display:none;margin-bottom:8px;padding:8px 12px;border-radius:10px;background:#fde8e8;color:#b42318;font-size:13px;"></div>
                            <form id="chatForm" onsubmit="handleSendMessage(event)">
                                <div class="chat-input-wrapper">
                                    <input type="text" id="chatInput" placeholder="Type a message to <?php echo e($activeCounselor['full_name']); ?>..." autocomplete="off" required>
                                    <button type="submit" class="btn btn-primary" id="chatSendBtn" style="padding:10px 18px;">
                                        Send &rarr;
                                    </button>
                                </div>
                            </form>
                            <div style="font-size:12px;color:var(--muted);margin-top:8px;">Each account keeps its 3 most recent messages in this conversation. Older ones are removed automatically.</div>
                            <div class="chat-quick-replies">
                                <span class="quick-chip" onclick="quickReply('Good day Counselor, can we schedule a consultation?')">Request schedule</span>
                                <span class="quick-chip" onclick="quickReply('Can we meet on Google Meet?')">Google Meet request</span>
                                <span class="quick-chip" onclick="quickReply('Thank you for your guidance!')">Thank you</span>
                            </div>
                        </div>
                    <?php else: ?>
                        <div style="display:flex;align-items:center;justify-content:center;height:100%;color:var(--muted);">
                            <p>Select a guidance counselor from the list to begin private consultation.</p>
                        </div>
                    <?php endif; ?>
                </section>
            </div>

            <script>
            const currentUserId = "<?php echo e($uid); ?>";
            const targetUserId = "<?php echo e($activeCounselorId); ?>";

            function quickReply(text) {
                const input = document.getElementById('chatInput');
                input.value = text;
                input.focus();
            }

            async function loadMessages() {
                if (!targetUserId) return;
                try {
                    const res = await fetch('/api/chat.php?with=' + encodeURIComponent(targetUserId));
                    if (!res.ok) return;
                    const data = await res.json();
                    renderMessages((data.messages || []).sort((a, b) => new Date(a.created_at) - new Date(b.created_at)));
                } catch (e) {
                    console.error('Error fetching chat messages', e);
                }
            }

            function renderMessages(messages) {
                const container = document.getElementById('chatMessages');
                if (!container) return;

                if (messages.length === 0) {
                    container.innerHTML = '<div class="chat-empty-state"><p>No messages yet. Send a message to start your confidential counseling dialogue.</p></div>';
                    return;
                }

                const atBottom = (container.scrollHeight - container.scrollTop <= container.clientHeight + 100);

                let html = '';
                let lastDate = '';

                messages.forEach(m => {
                    const isMe = (m.sender_id === currentUserId);
                    const dt = new Date(m.created_at);
                    const timeStr = dt.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' });
                    const dateStr = dt.toLocaleDateString([], { month: 'short', day: 'numeric', year: dt.getFullYear() === new Date().getFullYear() ? undefined : 'numeric' });

                    if (dateStr !== lastDate) {
                        html += `<div class="chat-date-divider"><span>${dateStr}</span></div>`;
                        lastDate = dateStr;
                    }

                    html += `
                        <div class="chat-bubble-row ${isMe ? 'outgoing' : 'incoming'}">
                            <div class="chat-bubble">
                                <div class="chat-bubble-text">${formatMessage(m.message)}</div>
                                <div class="chat-bubble-meta">
                                    <span>${timeStr}</span>
                                    ${isMe ? (m.is_read ? '<span class="read-receipt" title="Read">&#10003;&#10003;</span>' : '<span class="read-receipt" title="Sent">&#10003;</span>') : ''}
                                </div>
                            </div>
                        </div>
                    `;
                });

                container.innerHTML = html;

                if (atBottom || document.getElementById('chatLoading')) {
                    container.scrollTop = container.scrollHeight;
                }
            }

            async function handleSendMessage(e) {
                e.preventDefault();
                const input = document.getElementById('chatInput');
                const btn = document.getElementById('chatSendBtn');
                const text = input.value.trim();
                if (!text || !targetUserId) return;

                showChatError('');
                btn.disabled = true;
                try {
                    const res = await fetch('/api/chat.php', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify({ receiver_id: targetUserId, message: text })
                    });
                    const data = await res.json().catch(() => ({}));
                    if (res.ok) {
                        input.value = '';
                        await loadMessages();
                    } else {
                        showChatError(data.error || 'Message could not be sent. Please try again.');
                    }
                } catch (err) {
                    console.error('Failed to send message', err);
                    showChatError('Network problem. Your message was not sent.');
                } finally {
                    btn.disabled = false;
                    input.focus();
                }
            }

            function showChatError(msg) {
                const box = document.getElementById('chatError');
                if (!box) return;
                box.textContent = msg;
                box.style.display = msg ? 'block' : 'none';
            }

            function formatMessage(text) {
                return escapeHtml(text).replace(/(https?:\/\/[^\s]+)/g,
                    '<a href="$1" target="_blank" rel="noopener noreferrer" style="text-decoration:underline;color:inherit;font-weight:700;">$1</a>');
            }

            function escapeHtml(str) {
                return String(str)
                    .replace(/&/g, '&amp;')
                    .replace(/</g, '&lt;')
                    .replace(/>/g, '&gt;')
                    .replace(/"/g, '&quot;');
            }

            // Initial load + periodic real-time sync every 3 seconds
            loadMessages();
            setInterval(() => { if (!document.hidden) loadMessages(); }, 4000);
            document.addEventListener('visibilitychange', () => { if (!document.hidden) loadMessages(); });
            </script>
<?php page_end(); ?>