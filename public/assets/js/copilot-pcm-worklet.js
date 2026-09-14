/* Dedicated silent tap. Integrate source samples over each 16kHz sample window;
 * fractional coverage and pending PCM survive every browser render quantum. */
class CopilotPCM extends AudioWorkletProcessor {
    constructor() {
        super();
        this.ratio = sampleRate / 16000;
        this.remaining = this.ratio;
        this.sum = 0;
        this.buffer = new ArrayBuffer(3200);
        this.view = new DataView(this.buffer);
        this.offset = 0;
    }
    process(inputs) {
        const channels = inputs[0];
        if (!channels?.length) return true;
        for (let i = 0; i < channels[0].length; i++) {
            let sample = 0;
            for (const channel of channels) sample += channel[i] || 0;
            sample /= channels.length;
            let available = 1;
            while (available > 1e-10) {
                const take = Math.min(available, this.remaining);
                this.sum += sample * take;
                available -= take;
                this.remaining -= take;
                if (this.remaining < 1e-10) {
                    const value = Math.max(-1, Math.min(1, this.sum / this.ratio));
                    this.view.setInt16(this.offset, Math.round(value * (value < 0 ? 32768 : 32767)), true);
                    this.offset += 2;
                    this.sum = 0;
                    this.remaining = this.ratio;
                    if (this.offset === 3200) {
                        this.port.postMessage(this.buffer, [this.buffer]);
                        this.buffer = new ArrayBuffer(3200);
                        this.view = new DataView(this.buffer);
                        this.offset = 0;
                    }
                }
            }
        }
        // No output writes: connected destination receives silence only.
        return true;
    }
}
registerProcessor('copilot-pcm', CopilotPCM);
