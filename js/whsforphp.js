// Simple WebSocket handler for Home Assistant real-time updates
class WebSocketHandler {
    constructor(haUrl, accessToken) {
        this.wsUrl = haUrl.replace('http', 'ws') + '/api/websocket';
        this.accessToken = accessToken;
        this.messageId = 1;
        this.subscriptions = new Map();
        this.isAuthenticated = false;
        this.readyCallbacks = [];
        this.onStatusChange = (status) => {
            const wsStatus = document.getElementById('websocket-status');
            wsStatus.textContent = `WebSocket: ${status}`;
        };
    }

    connect() {
        this.ws = new WebSocket(this.wsUrl);
        console.log('1: Connecting');

        this.ws.onopen = () => {
            console.log('2: Connected');
            this.onStatusChange('Connected');
            this.authenticate();
        };

        this.ws.onmessage = (event) => {
            const data = JSON.parse(event.data);
            this.handleMessage(data);
        };

        this.ws.onclose = () => {
            this.onStatusChange('Disconnected');
            this.isAuthenticated = false;
        };

        this.ws.onerror = (error) => {
            console.error('WebSocket error:', error);
            this.onStatusChange('Error');
        };
    }

    authenticate() {
        console.log('3: Authenticating with access token');
        const authMessage = {
            type: 'auth',
            access_token: this.accessToken
        };
        this.sendMessage(authMessage);
    }

    isReady() {
        return this.ws && this.ws.readyState === WebSocket.OPEN && this.isAuthenticated;
    }

    whenReady(callback) {
        if (this.isReady()) {
            console.log('1.1 WebSocket is ready, calling callback');
            callback();
        } else {
            console.log('1.1 WebSocket is not ready, adding callback to queue');
            this.readyCallbacks.push(callback);
        }
    }

    // Subscribe to specific entities using triggers (efficient)
    subscribeToStates(entityIds, callback) {
        console.log('7: Subscribing to states');
        // console.log('Entities', entityIds);
        const subscriptionIds = [];

        entityIds.forEach(entityId => {
            const subscriptionId = this.messageId++;

            console.log(`8: Creating trigger subscription for entity: ${entityId} with ID: ${subscriptionId}`);

            const message = {
                id: subscriptionId,
                type: 'subscribe_trigger',
                trigger: {
                    platform: 'state',
                    entity_id: entityId
                }
            };

            this.subscriptions.set(subscriptionId, {
                entityId: entityId,
                callback: callback,
                type: 'trigger'
            });

            this.sendMessage(message);
            subscriptionIds.push(subscriptionId);
        });

        return subscriptionIds;
    }

    sendMessage(message) {

        if (message.type === 'auth') {
            console.log('4: Sending auth message');
        } else if (message.type === 'subscribe_events') {
            console.log('9-1: Sending subscribe EVENTS message:', message);
        } else if (message.type === 'subscribe_trigger') {
            console.log('9-2: Sending subscribe TRIGGER message:', message);
        } else if (message.type === 'unsubscribe_events') {
            console.log('10: Sending unsubscribe message:', message);
        } else {
            console.log('Unknown: Sending message:', message);
        }

        if (this.ws && this.ws.readyState === WebSocket.OPEN) {
            this.ws.send(JSON.stringify(message));
            return true;
        }
        return false;
    }

    handleMessage(data) {
        // console.log('Received message:', data);
        if (data.type === 'auth_ok') {
            console.log('5: Authentication successful: Inside HandleMessage');
            this.isAuthenticated = true;
            console.log('6: Calling ready callbacks: Inside HandleMessage');
            this.readyCallbacks.forEach(callback => callback());
            this.readyCallbacks = [];
            return;
        }

        if (data.type === 'auth_invalid') {
            console.error('Authentication failed:', data.message);
            return;
        }

        if (data.type === 'event') {
            console.log('10: Calling callback: Inside HandleMessage');
            const subscription = this.subscriptions.get(data.id);

            if (subscription && subscription.callback) {
                if (subscription.type === 'trigger') {
                    // console.log(subscription);
                    // Handle trigger events
                    const triggerData = {
                        entity_id: subscription.entityId,
                        new_state: data.event.variables.trigger.to_state,
                        old_state: data.event.variables.trigger.from_state
                    };
                    subscription.callback(triggerData);
                }
            }
        }
    }

    callService(domain, service, entityId) {
        const message = {
            id: this.messageId++,
            type: 'call_service',
            domain: domain,
            service: service,
            target: {
                entity_id: entityId
            }
        };
        return this.sendMessage(message);
    }

    close() {
        if (this.ws) {
            this.ws.close();
        }
    }
}
