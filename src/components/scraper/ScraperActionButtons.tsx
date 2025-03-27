
import { Button } from "@/components/ui/button";
import { Loader2, RefreshCw, History } from "lucide-react";

interface ScraperActionButtonsProps {
  isScrapingNow: boolean;
  isHistoricalScraping: boolean;
  onTestScrape: () => Promise<void>;
  onHistoricalScrape: () => Promise<void>;
}

const ScraperActionButtons = ({
  isScrapingNow,
  isHistoricalScraping,
  onTestScrape,
  onHistoricalScrape
}: ScraperActionButtonsProps) => {
  return (
    <div className="flex flex-wrap gap-2 mb-4">
      <Button 
        variant="default" 
        onClick={onTestScrape}
        disabled={isScrapingNow || isHistoricalScraping}
      >
        {isScrapingNow ? (
          <>
            <Loader2 className="h-4 w-4 mr-2 animate-spin" />
            Testing Sources...
          </>
        ) : (
          <>
            <RefreshCw className="h-4 w-4 mr-2" />
            Test Scraper Sources
          </>
        )}
      </Button>
      
      <Button 
        variant="outline" 
        onClick={onHistoricalScrape}
        disabled={isScrapingNow || isHistoricalScraping}
      >
        {isHistoricalScraping ? (
          <>
            <Loader2 className="h-4 w-4 mr-2 animate-spin" />
            Retrieving...
          </>
        ) : (
          <>
            <History className="h-4 w-4 mr-2" />
            Test Historical Scrape
          </>
        )}
      </Button>
    </div>
  );
};

export default ScraperActionButtons;
