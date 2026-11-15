<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/layout.php';

$me = require_role('counselor');
$uid = $me['user']['id'];
$token = $me['token'];

// Fetch patients who have appointments or have sent messages to this counselor
$patients = get_counselor_patients($uid, $token);

// If no patients from appointments yet, also fetch patients who registered
if (empty($patients)) {
    $pr = sb_request('GET', 'profiles', 'select=id,full_name,email,patient_type,grade_or_dept&role=eq.patient&order=created_at.desc&limit=20', null, $token);
    $patients = ($pr['status'] === 200 && is_array($pr['data'])) ? $pr['data'] : [];
}

$activePatientId = $_GET['with'] ?? ($patients[0]['id'] ?? '');
$activePatient = null;
foreach ($patients as $p) {
    if ($p['id'] === $activePatientId) {
        $activePatient = $p;
        break;
    }
}

// Generate Google Meet Link for quick online consultation
$meetLink = generate_google_meet_link($uid . $activePatientId);

$fullName = $me['profile']['full_name'] ?? 'Counselor';
$ini = '';
foreach (array_slice(explode(' ', $fullName), 0, 2) as $w) { $ini .= strtoupper(substr($w, 0, 1)); }

$rightContent = '
    <div class="profile-interactable">
        <span class="profile-avatar">' . e($ini) . '</span>
        <div class="profile-info">
            <strong>' . e($fullName) . '</strong>
            <small>Guidance Counselor</small>
        </div>
    </div>
';

page_start('counselor', 'Consultation Messages', '/counselor/chat.php', 'Student Consultation Messages', 'Direct, private messaging channel with your assigned students.', $rightContent);
?>
            <div class="chat-container">
                <!-- Sidebar: Student Roster -->
                <aside class="chat-sidebar">
                    <div class="chat-sidebar-header">
                        <h3>Students &bull; Active Cases</h3>
                        <small>Direct counseling channel</small>
                    </div>
                    <div class="chat-user-list">
                        <?php if (empty($patients)): ?>
                            <p class="empty" style="padding:16px;">No student records found yet.</p>
                        <?php else: foreach ($patients as $p): 
                            $isActive = ($p['id'] === $activePatientId);
                            $pIni = '';
                            foreach (array_slice(explode(' ', $p['full_name']), 0, 2) as $w) { $pIni .= strtoupper(substr($w, 0, 1)); }
                        ?>
                            <a href="/counselor/chat.php?with=<?php echo urlencode($p['id']); ?>" class="chat-user-item <?php echo $isActive ? 'active' : ''; ?>">
                                <div class="profile-avatar" style="width:38px;height:38px;font-size:14px;background:var(--sand);color:var(--ink);"><?php echo e($pIni); ?></div>
                                <div class="chat-user-info">
                                    <strong><?php echo e($p['full_name']); ?></strong>
                                    <small><?php echo e($p['grade_or_dept'] ?? 'Student'); ?></small>
                                </div>
                                <span class="online-indicator" title="Active Student"></span>
                            </a>
                        <?php endforeach; endif; ?>
                    </div>
                </aside>

                <!-- Main Chat Pane -->
                <section class="chat-main">
                    <?php if ($activePatient): ?>
                        <div class="chat-header">
                            <div class="chat-header-user">
                                <div class="profile-avatar" style="width:42px;height:42px;background:var(--sand);color:var(--ink);"><?php echo strtoupper(substr($activePatient['full_name'], 0, 1)); ?></div>
                                <div>
                                    <h3 style="margin:0;font-size:16px;"><?php echo e($activePatient['full_name']); ?></h3>
                                    <small style="color:var(--muted);"><?php echo e($activePatient['grade_or_dept'] ?? 'Student'); ?> &bull; <?php echo e($activePatient['email'] ?? ''); ?></small>
                                </div>
                            </div>
                            <div class="chat-header-actions">
                                <a href="<?php echo e($meetLink); ?>" target="_blank" rel="noopener noreferrer" class="btn btn-sm" style="display:inline-flex;align-items:center;gap:6px;background:var(--surface);border-color:var(--primary);color:var(--primary);" title="Launch Google Meet Video Call">
                                    <svg class="icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="color:#00897B;"><polygon points="23 7 16 12 23 17 23 7"/><rect x="1" y="5" width="15" height="14" rx="2" ry="2"/></svg>
                                    <span>Google Meet</span>
                                </a>
                                <a href="/counselor/record.php" class="btn-sm">Clinical Records</a>
                            </div>
                        </div>

                        <!-- Messages Stream -->
                        <div class="chat-messages" id="chatMessages">
                            <div class="chat-loading" id="chatLoading">Loading student consultation dialogue...</div>
                        </div>

                        <!-- Chat Input Box -->
                        <div class="chat-input-bar">
                            <form id="chatForm" onsubmit="handleSendMessage(event)">
                                <div class="chat-input-wrapper">
                                    <input type="text" id="chatInput" placeholder="Message <?php echo e($activePatient['full_name']); ?> (e.g. appointment updates, advice)..." autocomplete="off" required>
                                    <button type="submit" class="btn btn-primary" id="chatSendBtn" style="padding:10px 18px;">
                                        Send &rarr;
                                    </button>
                                </div>
                            </form>
                            <div class="chat-quick-replies">
                                <span class="quick-chip" onclick="quickReply('Hello! How are you doing today?')">Greeting</span>
                                <span class="quick-chip" onclick="quickReply('Please join our Google Meet call: <?php echo e($meetLink); ?>')">Send Google Meet link</span>
                                <span class="quick-chip" onclick="quickReply('Your consultation summary and recommendations have been shared in your portal.')">Shared summary notice</span>
                            </div>
                        </div>
                    <?php else: ?>
                        <div style="display:flex;align-items:center;justify-content:center;height:100%;color:var(--muted);">
                            <p>Select a student from the active list to review messages or begin consultation.</p>
                        </div>
                    <?php endif; ?>
                </section>
            </div>

            <script>
            const currentUserId = "<?php echo e($uid); ?>";
            const targetUserId = "<?php echo e($activePatientId); ?>";

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
                    renderMessages(data.messages || []);
                } catch (e) {
                    console.error('Error fetching chat messages', e);
                }
            }

            function renderMessages(messages) {
                const container = document.getElementById('chatMessages');
                if (!container) return;

                if (messages.length === 0) {
                    container.innerHTML = '<div class="chat-empty-state"><p>No consultation messages yet with this student. Send a warm greeting or appointment link below.</p></div>';
                    return;
                }

                const atBottom = (container.scrollHeight - container.scrollTop <= container.clientHeight + 100);

                let html = '';
                let lastDate = '';

                messages.forEach(m => {
                    const isMe = (m.sender_id === currentUserId);
                    const dt = new Date(m.created_at);
                    const timeStr = dt.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' });
                    const dateStr = dt.toLocaleDateString([], { month: 'short', day: 'numeric' });

                    if (dateStr !== lastDate) {
                        html += `<div class="chat-date-divider"><span>${dateStr}</span></div>`;
                        lastDate = dateStr;
                    }

                    // Format links inside messages
                    let msgContent = escapeHtml(m.message);
                    msgContent = msgContent.replace(/(https?:\/\/[^\s]+)/g, '<a href="$1" target="_blank" rel="noopener noreferrer" style="text-decoration:underline;color:inherit;font-weight:700;">$1</a>');

                    html += `
                        <div class="chat-bubble-row ${isMe ? 'outgoing' : 'incoming'}">
                            <div class="chat-bubble">
                                <div class="chat-bubble-text">${msgContent}</div>
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
                const text = input.value.trim();
                if (!text || !targetUserId) return;

                input.value = '';
                input.focus();

                try {
                    const res = await fetch('/api/chat.php', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify({
                            receiver_id: targetUserId,
                            message: text
                        })
                    });
                    if (res.ok) {
                        loadMessages();
                    }
                } catch (e) {
                    console.error('Failed to send message', e);
                }
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
            setInterval(loadMessages, 3000);
            </script>
<?php page_end(); ?>
