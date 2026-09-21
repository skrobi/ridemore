/**
 * Ranking Modal Module
 * Handles challenge leaderboard display
 */

class RankingModal {
    constructor() {
        this.currentPage = 1;
        this.currentChallengeId = null;
        this.currentUserId = null;
        this.challengeName = null;
        this.modal = null;

        this.injectModalHTML();
        this.initEventListeners();
    }

    injectModalHTML() {
        // Create modal container
        const modalHTML = `
            <div id="modal-ranking" class="modal-overlay" style="display: none;">
                <div class="modal-container ranking-modal">
                    <div class="modal-header">
                        <div class="modal-title-wrapper">
                            <svg class="trophy-icon" xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                <path d="M6 9H4.5a2.5 2.5 0 0 1 0-5H6"></path>
                                <path d="M18 9h1.5a2.5 2.5 0 0 0 0-5H18"></path>
                                <path d="M4 22h16"></path>
                                <path d="M10 14.66V17c0 .55-.47.98-.97 1.21C7.85 18.75 7 20.24 7 22"></path>
                                <path d="M14 14.66V17c0 .55.47.98.97 1.21C16.15 18.75 17 20.24 17 22"></path>
                                <path d="M18 2H6v7a6 6 0 0 0 12 0V2Z"></path>
                            </svg>
                            <h2 id="modal-title">Ranking</h2>
                        </div>
                        <button class="modal-close" onclick="closeRankingModal()">×</button>
                    </div>
                    
                    <div class="modal-body">
                        <!-- Challenge Info -->
                        <div class="ranking-header">
                            <h3 id="ranking-challenge-name">Ładowanie...</h3>
                            <div class="ranking-stats">
                                <span id="ranking-participants">0 uczestników</span>
                                <span class="separator">•</span>
                                <span id="ranking-distance">0 km</span>
                                <span class="separator">•</span>
                                <span id="ranking-your-position">Twoja pozycja: -</span>
                            </div>
                        </div>
                        
                        <!-- Ranking Table -->
                        <div class="ranking-table-container">
                            <table class="ranking-table">
                                <thead>
                                    <tr>
                                        <th style="width: 80px;">Pozycja</th>
                                        <th>Użytkownik</th>
                                        <th style="width: 150px;">Pokrycie</th>
                                        <th style="width: 100px;">Dystans</th>
                                        <th style="width: 100px;" class="hide-mobile">Czas</th>
                                        <th style="width: 100px;">Punkty</th>
                                        <th style="width: 80px;" class="hide-mobile">Jazdy</th>
                                    </tr>
                                </thead>
                                <tbody id="ranking-tbody">
                                    <!-- Dynamic rows -->
                                </tbody>
                            </table>
                        </div>
                        
                        <!-- Loading State -->
                        <div id="ranking-loading" class="ranking-loading" style="display: none;">
                            <div class="spinner"></div>
                            <p>Ładowanie rankingu...</p>
                        </div>
                        
                        <!-- Empty State -->
                        <div id="ranking-empty" class="ranking-empty" style="display: none;">
                            <p>Brak danych rankingowych</p>
                        </div>
                        
                        <!-- Pagination -->
                        <div class="ranking-pagination" id="ranking-pagination" style="display: none;">
                            <button id="ranking-prev" onclick="loadRankingPage(rankingModal.currentPage - 1)">← Poprzednia</button>
                            <span id="ranking-page-info">Strona 1 z 1</span>
                            <button id="ranking-next" onclick="loadRankingPage(rankingModal.currentPage + 1)">Następna →</button>
                        </div>
                    </div>
                </div>
            </div>
        `;

        // Inject at end of body
        document.body.insertAdjacentHTML('beforeend', modalHTML);
        this.modal = document.getElementById('modal-ranking');
    }

    initEventListeners() {
        // Close on ESC key
        document.addEventListener('keydown', (e) => {
            if (e.key === 'Escape' && this.isOpen()) {
                this.close();
            }
        });

        // Close on overlay click
        document.addEventListener('click', (e) => {
            if (e.target.id === 'modal-ranking') {
                this.close();
            }
        });
    }

    isOpen() {
        return this.modal && this.modal.style.display === 'flex';
    }

    async open(challengeId, challengeName, userLocalId) {
        this.currentChallengeId = challengeId;
        this.challengeName = challengeName;
        this.currentUserId = userLocalId;
        this.currentPage = 1;

        if (!this.modal) {
            console.error('Ranking modal not found in DOM');
            return;
        }

        // Set title immediately
        const titleEl = document.getElementById('modal-title');
        if (titleEl && challengeName) {
            titleEl.textContent = challengeName;
        }

        this.modal.style.display = 'flex';
        document.body.style.overflow = 'hidden';

        await this.loadPage(1);
    }

    close() {
        if (this.modal) {
            this.modal.style.display = 'none';
        }
        document.body.style.overflow = '';
    }

    async loadPage(page) {
        if (page < 1)
            return;

        this.currentPage = page;

        const loading = document.getElementById('ranking-loading');
        const empty = document.getElementById('ranking-empty');
        const tbody = document.getElementById('ranking-tbody');
        const pagination = document.getElementById('ranking-pagination');

        // Show loading state
        loading.style.display = 'block';
        empty.style.display = 'none';
        tbody.innerHTML = '';
        pagination.style.display = 'none';

        try {
            const url = window.APP_CONFIG.api(`rankings/challenge-leaderboard.php?challenge_id=${this.currentChallengeId}&page=${page}&per_page=50&user_local_id=${this.currentUserId}`);

            //console.log('📡 Fetching ranking:', url);

            const response = await fetch(url);
            const result = await response.json();

            //console.log('📥 Ranking response:', result);

            if (!result.success) {
                throw new Error(result.message || 'Failed to load ranking');
            }

            const data = result.data;

            // Validate data structure
            if (!data.leaderboard || !data.pagination || !data.challenge) {
                console.error('Invalid data structure:', data);
                throw new Error('Invalid response structure');
            }

            // Update header with challenge info
            this.updateHeader(data);

            // Render rows
            if (data.leaderboard.length === 0) {
                loading.style.display = 'none';
                empty.style.display = 'block';
                return;
            }

            this.renderLeaderboard(data.leaderboard, data.current_user, data.challenge);

            // Show pagination
            this.updatePagination(data.pagination);

            loading.style.display = 'none';

        } catch (error) {
            console.error('❌ Ranking load error:', error);
            loading.style.display = 'none';
            empty.style.display = 'block';

            const emptyText = document.querySelector('.ranking-empty p');
            if (emptyText) {
                emptyText.textContent = 'Błąd ładowania rankingu: ' + error.message;
            }
        }
    }

    updateHeader(data) {
        //console.log('🔄 Updating header with data:', data);

        // Update main title (top of modal)
        const titleEl = document.getElementById('modal-title');
        if (titleEl && data.challenge) {
            titleEl.textContent = data.challenge.name;
            //console.log('✅ Title set to:', data.challenge.name);
        }

        // Update challenge name in stats section
        const nameEl = document.getElementById('ranking-challenge-name');
        if (nameEl && data.challenge) {
            nameEl.textContent = data.challenge.name;
        }

        // Update participants count
        const participantsEl = document.getElementById('ranking-participants');
        if (participantsEl) {
            participantsEl.textContent = `${data.pagination.total} uczestników`;
        }

        // Update total distance
        const distanceEl = document.getElementById('ranking-distance');
        if (distanceEl && data.challenge) {
            const totalKm = parseFloat(data.challenge.total_distance_km || 0);
            distanceEl.textContent = `${totalKm.toFixed(1)} km`;
        }

        // Update user position
        const positionEl = document.getElementById('ranking-your-position');
        if (positionEl && data.current_user) {
            const score = parseInt(data.current_user.total_score || 0).toLocaleString();
            positionEl.textContent = `Twoja pozycja: #${data.current_user.rank} (${score} pkt)`;
        } else if (positionEl) {
            positionEl.textContent = 'Nie uczestniczysz';
        }
    }

    renderLeaderboard(leaderboard, currentUser, challenge) {
        const tbody = document.getElementById('ranking-tbody');
        if (!tbody)
            return;

        // ✅ DODAJ DEBUG
        //console.log('renderLeaderboard called with:', {
        //    leaderboard_count: leaderboard.length,
       //    currentUser: currentUser,
        //    challenge: challenge
        //});

        const totalDistance = parseFloat(challenge.total_distance_km || 0);

        tbody.innerHTML = leaderboard.map(entry =>
            this.renderRow(entry, currentUser, totalDistance)
        ).join('');

        // Auto-scroll do current user
        setTimeout(() => {
            const currentUserRow = document.getElementById('current-user-row');
            if (currentUserRow) {
                currentUserRow.scrollIntoView({
                    behavior: 'smooth',
                    block: 'center'
                });
            }
        }, 100);
    }

    renderRow(entry, currentUser, totalChallengeDistance) {
        const isCurrentUser = currentUser && entry.user_id === currentUser.user_id;
        const rankClass = this.getRankClass(entry.rank);
        const rankDisplay = this.getRankDisplay(entry.rank);
        const initials = this.getUserInitials(entry.username);

        // ✅ Coverage percentage
        const coveragePct = parseFloat(entry.coverage_pct || 0);

        // ✅ Distance calculation: total_challenge_distance * coverage%
        const userDistance = (totalChallengeDistance * coveragePct) / 100;

        // ✅ Moving time (czas jazdy)
        const movingTimeMin = parseInt(entry.total_moving_time_min || 0);
        const movingHours = Math.floor(movingTimeMin / 60);
        const movingMinutes = movingTimeMin % 60;
        const movingDisplay = movingTimeMin > 0
                ? `${movingHours}h ${movingMinutes}m`
                : '-';

        // ✅ Elapsed time (dla tooltip)
        const elapsedTimeMin = parseInt(entry.total_elapsed_time_min || 0);
        const hasBreaks = elapsedTimeMin > movingTimeMin && elapsedTimeMin > 0;
        const elapsedHours = Math.floor(elapsedTimeMin / 60);
        const elapsedMinutes = elapsedTimeMin % 60;

        const timeTooltip = hasBreaks
                ? `title="Czas całkowity: ${elapsedHours}h ${elapsedMinutes}m (z przerwami)"`
                : '';

        // ✅ Score
        const score = parseInt(entry.total_score || 0);

        // ✅ Activities count
        const activitiesCount = parseInt(entry.activities_count || 0);

        return `
            <tr class="${isCurrentUser ? 'current-user' : ''}" ${isCurrentUser ? 'id="current-user-row"' : ''}>
                <td>
                    <div class="rank-badge ${rankClass}">
                        ${rankDisplay}
                    </div>
                </td>
                <td>
                    <div class="user-info">
                        <div class="user-avatar">${initials}</div>
                        <span class="user-name">${this.escapeHtml(entry.username || 'Użytkownik')}</span>
                    </div>
                </td>
                <td>
                    <div class="coverage-bar-container">
                        <div class="coverage-bar">
                            <div class="coverage-fill" style="width: ${coveragePct}%"></div>
                        </div>
                        <span class="coverage-pct">${coveragePct.toFixed(1)}%</span>
                    </div>
                </td>
                <td>
                    <span class="distance">${userDistance.toFixed(1)} km</span>
                </td>
                <td class="hide-mobile">
                    <span class="time" ${timeTooltip}>${movingDisplay}</span>
                </td>
                <td>
                    <span class="score">${score.toLocaleString()}</span>
                </td>
                <td class="hide-mobile">
                    <span class="activities">${activitiesCount}</span>
                </td>
            </tr>
        `;
    }

    updatePagination(pagination) {
        const paginationEl = document.getElementById('ranking-pagination');
        const pageInfo = document.getElementById('ranking-page-info');
        const prevBtn = document.getElementById('ranking-prev');
        const nextBtn = document.getElementById('ranking-next');

        if (pagination.total_pages <= 1) {
            if (paginationEl) {
                paginationEl.style.display = 'none';
            }
            return;
        }

        if (paginationEl) {
            paginationEl.style.display = 'flex';
        }

        if (pageInfo) {
            pageInfo.textContent = `Strona ${this.currentPage} z ${pagination.total_pages}`;
        }

        if (prevBtn) {
            prevBtn.disabled = this.currentPage === 1;
        }

        if (nextBtn) {
            nextBtn.disabled = this.currentPage === pagination.total_pages;
        }
    }

    // Helper methods
    getRankClass(rank) {
        if (rank === 1)
            return 'top-1';
        if (rank === 2)
            return 'top-2';
        if (rank === 3)
            return 'top-3';
        return 'other';
    }

    getRankDisplay(rank) {
        if (rank === 1)
            return '🥇';
        if (rank === 2)
            return '🥈';
        if (rank === 3)
            return '🥉';
        return rank;
    }

    getUserInitials(username) {
        if (!username)
            return 'U';
        return username.substring(0, 2).toUpperCase();
    }

    escapeHtml(text) {
        const div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    }
}

// Initialize on DOM ready
if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', () => {
        window.rankingModal = new RankingModal();
    });
} else {
    window.rankingModal = new RankingModal();
}

// Global functions for inline onclick handlers
function openRankingModal(challengeId, challengeName, userLocalId) {
    if (!challengeId || !challengeName || !userLocalId) {
        console.error('Missing required parameters for ranking modal');
        return;
    }
    window.rankingModal.open(challengeId, challengeName, userLocalId);
}

function closeRankingModal() {
    window.rankingModal.close();
}

function loadRankingPage(page) {
    window.rankingModal.loadPage(page);
}