/* ---------- Search Patient Modal Popup ---------- */
.modal-overlay {
  position: fixed;
  top: 0;
  left: 0;
  width: 100%;
  height: 100%;
  background: rgba(31, 42, 36, 0.55);
  backdrop-filter: blur(2px);
  display: flex;
  align-items: center;
  justify-content: center;
  z-index: 1000;
  padding: 20px;
}
.modal-card {
  background: var(--surface);
  border: 1px solid var(--line);
  border-radius: 16px;
  width: 100%;
  max-width: 600px;
  box-shadow: 0 20px 40px rgba(31, 42, 36, 0.18);
  display: flex;
  flex-direction: column;
  overflow: hidden;
  animation: modalFadeIn 0.2s ease-out;
}
@keyframes modalFadeIn {
  from { transform: translateY(12px); opacity: 0; }
  to { transform: translateY(0); opacity: 1; }
}
.modal-header {
  display: flex;
  justify-content: space-between;
  align-items: center;
  padding: 18px 24px;
  border-bottom: 1px solid var(--line);
  background: #FBF9F5;
}
.modal-header h2 {
  font-family: var(--serif);
  font-size: 20px;
  font-weight: 600;
  margin: 0;
  color: var(--ink);
}
.modal-close-btn {
  background: #fff;
  border: 1px solid var(--line);
  color: var(--muted);
  width: 32px;
  height: 32px;
  border-radius: 50%;
  display: inline-flex;
  align-items: center;
  justify-content: center;
  font-weight: 700;
  font-size: 16px;
  text-decoration: none;
  transition: all 0.2s ease;
}
.modal-close-btn:hover {
  background: var(--danger);
  color: #fff;
  border-color: var(--danger);
}
.modal-body {
  padding: 20px 24px;
  max-height: 420px;
  overflow-y: auto;
}
.patient-popup-item {
  display: flex;
  justify-content: space-between;
  align-items: center;
  padding: 14px 16px;
  border: 1px solid var(--line);
  border-radius: 12px;
  margin-bottom: 10px;
  background: #FBF9F5;
}
.patient-popup-item:last-child {
  margin-bottom: 0;
}
.patient-popup-info strong {
  display: block;
  font-size: 15px;
  color: var(--ink);
  margin-bottom: 2px;
}
.patient-popup-info small {
  color: var(--muted);
  font-size: 13px;
}